<?php

namespace SilverStripe\UserDocs\Tests\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserDocs\Markdown\ExtensionDocAssembler;
use SilverStripe\UserDocs\Markdown\ExtensionDocMarker;
use Symfony\Component\Filesystem\Path;

class ExtensionDocAssemblerTest extends SapphireTest
{
    public function testGetExtensionData(): void
    {
        $docData = [
            'extensions' => [
                'module/one' => ['filePath' => 'one.md'],
                'module/two' => ['filePath' => 'two.md'],
            ],
        ];

        $this->assertSame(
            [['filePath' => 'one.md'], ['filePath' => 'two.md']],
            ExtensionDocAssembler::create()->getExtensionData($docData)
        );
    }

    public function testGetExtensionDataWithoutExtensions(): void
    {
        $this->assertSame([], ExtensionDocAssembler::create()->getExtensionData(['filePath' => 'one.md']));
    }

    public function testGetMarkdown(): void
    {
        $originalPath = $this->fixturePath('original.md');
        $extensionPath = $this->fixturePath('extension.md');
        $docData = [
            'filePath' => $originalPath,
            'extensions' => [
                'same file' => ['filePath' => $originalPath, 'hasContent' => true],
                'no content' => ['filePath' => $extensionPath, 'hasContent' => false],
                'missing file' => ['filePath' => $this->fixturePath('missing.md'), 'hasContent' => true],
                'extension' => ['filePath' => $extensionPath, 'hasContent' => true],
            ],
        ];

        $markdown = ExtensionDocAssembler::create()->getMarkdown($docData);

        // Skipped extensions keep their index, so each marker matches the extension data
        $this->assertSame(
            file_get_contents($originalPath)
                . "\n\n" . ExtensionDocMarker::getMarker(3) . "\n\n"
                . "Appended.\n\n<!-- userdocs-insert-at: point -->\n\nInserted.\n",
            $markdown
        );
    }

    public function testAddToEnvironment(): void
    {
        $docData = [
            'filePath' => $this->fixturePath('original.md'),
            'extensions' => [
                'extension' => ['filePath' => $this->fixturePath('extension.md'), 'hasContent' => true],
            ],
        ];
        $assembler = ExtensionDocAssembler::create();
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());

        $assembler->addToEnvironment($environment, $docData);
        $html = (new MarkdownConverter($environment))->convert($assembler->getMarkdown($docData))->getContent();

        // Content is moved to the extension point, and the markers aren't rendered
        $this->assertSame(
            "<h1>Original</h1>\n<p>Inserted.</p>\n<p>Original end.</p>\n<p>Appended.</p>\n",
            $html
        );
    }

    public static function provideAddToEnvironmentWarnings(): array
    {
        return [
            'log warnings' => [
                'logWarnings' => true,
                'expectWarning' => true,
            ],
            "don't log warnings" => [
                'logWarnings' => false,
                'expectWarning' => false,
            ],
        ];
    }

    #[DataProvider('provideAddToEnvironmentWarnings')]
    public function testAddToEnvironmentWarnings(bool $logWarnings, bool $expectWarning): void
    {
        $warnings = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message) use (&$warnings) {
            $warnings[] = $message;
        });
        Injector::inst()->registerService($logger, LoggerInterface::class);
        $extensionPath = $this->fixturePath('extension.md');
        $docData = [
            'filePath' => $this->fixturePath('unclosed_code.md'),
            'extensions' => [
                'extension' => ['filePath' => $extensionPath, 'hasContent' => true],
            ],
        ];
        $assembler = ExtensionDocAssembler::create();
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());

        $assembler->addToEnvironment($environment, $docData, $logWarnings);
        (new MarkdownConverter($environment))->convert($assembler->getMarkdown($docData));

        // The unclosed code block hides the marker for the extension
        $expected = [];
        if ($expectWarning) {
            $expected[] = "Could not find where the content from '$extensionPath' starts, so it might be in the wrong"
                . ' place and its links and images might not work.'
                . ' Check the content before it for an unclosed code block or HTML block.';
        }
        $this->assertSame($expected, $warnings);
    }

    private function fixturePath(string $fileName): string
    {
        return Path::join(__DIR__, 'ExtensionDocAssemblerTest', $fileName);
    }
}
