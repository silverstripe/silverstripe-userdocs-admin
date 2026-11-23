<?php

namespace SilverStripe\UserDocs\Tests\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserDocs\Markdown\ExtensionContentBlock;
use SilverStripe\UserDocs\Markdown\ExtensionContentRenderer;

class ExtensionContentRendererTest extends SapphireTest
{
    public static function provideRender(): array
    {
        return [
            'block content' => [
                'isTight' => false,
                'children' => ['paragraph', 'break'],
                'expected' => "<div class=\"userdocs-extension-content\" lang=\"fr-CA\">\n<p>Text</p>\n<hr />\n</div>",
            ],
            'paragraphs that are not tight' => [
                'isTight' => false,
                'children' => ['paragraph', 'paragraph'],
                'expected' => "<div class=\"userdocs-extension-content\" lang=\"fr-CA\">\n<p>Text</p>\n"
                    . "<p>Text</p>\n</div>",
            ],
            'tight paragraphs' => [
                'isTight' => true,
                'children' => ['paragraph', 'paragraph'],
                'expected' => "<span class=\"userdocs-extension-content\" lang=\"fr-CA\">Text\nText</span>",
            ],
            'tight paragraphs with other content' => [
                'isTight' => true,
                'children' => ['paragraph', 'break'],
                'expected' => "<div class=\"userdocs-extension-content\" lang=\"fr-CA\">\nText\n<hr />\n</div>",
            ],
            'tight with no content' => [
                'isTight' => true,
                'children' => [],
                'expected' => "<div class=\"userdocs-extension-content\" lang=\"fr-CA\">\n\n</div>",
            ],
        ];
    }

    #[DataProvider('provideRender')]
    public function testRender(bool $isTight, array $children, string $expected): void
    {
        $block = new ExtensionContentBlock('fr-CA');
        $block->setTight($isTight);
        foreach ($children as $child) {
            $block->appendChild($this->createChild($child));
        }

        $this->assertSame($expected, $this->render($block));
    }

    private function createChild(string $type): Node
    {
        if ($type === 'break') {
            return new ThematicBreak();
        }
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Text('Text'));
        return $paragraph;
    }

    private function render(ExtensionContentBlock $block): string
    {
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        $renderer = new HtmlRenderer($environment);
        return (string) (new ExtensionContentRenderer())->render($block, $renderer);
    }
}
