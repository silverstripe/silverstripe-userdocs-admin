<?php

namespace SilverStripe\UserDocs\Tests\Task;

use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\UserDocs\Admin\UserDocsManifest;
use SilverStripe\UserDocs\Search\SearchIndex;
use SilverStripe\UserDocs\Task\IndexDocumentationTask;
use SilverStripe\UserDocs\Tests\Task\IndexDocumentationTaskTest\RecordingSearchIndex;
use SilverStripe\UserDocs\Tests\Task\IndexDocumentationTaskTest\RecordingUserDocsManifest;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;

class IndexDocumentationTaskTest extends SapphireTest
{
    private RecordingUserDocsManifest $manifest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manifest = new RecordingUserDocsManifest();
        Injector::inst()->registerService($this->manifest, UserDocsManifest::class);
        i18n::set_locale('en_US');
    }

    public static function provideRun(): array
    {
        return [
            'clear by default' => [
                'options' => [],
                'expectedCalls' => ['clearIndex', 'init:en', 'init:fr'],
            ],
            'no clear' => [
                'options' => ['--no-clear' => true],
                'expectedCalls' => ['init:en', 'init:fr'],
            ],
        ];
    }

    #[DataProvider('provideRun')]
    public function testRun(array $options, array $expectedCalls): void
    {
        $searchIndex = new RecordingSearchIndex();
        Injector::inst()->registerService($searchIndex, SearchIndex::class);

        $exitCode = $this->runTask($options);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertTrue($this->manifest->initialised);
        $this->assertSame($expectedCalls, $searchIndex->calls);
        // The locale is restored after each locale is indexed
        $this->assertSame('en_US', i18n::get_locale());
    }

    public function testRunNotSupported(): void
    {
        $searchIndex = new RecordingSearchIndex(false);
        Injector::inst()->registerService($searchIndex, SearchIndex::class);

        $exitCode = $this->runTask([]);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertSame([], $searchIndex->calls);
    }

    private function runTask(array $options): int
    {
        $task = new IndexDocumentationTask();
        $input = new ArrayInput($options, new InputDefinition($task->getOptions()));
        $input->setInteractive(false);
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: new BufferedOutput());
        return $task->run($input, $output);
    }
}
