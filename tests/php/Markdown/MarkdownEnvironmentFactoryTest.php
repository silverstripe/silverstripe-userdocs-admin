<?php

namespace SilverStripe\UserDocs\Tests\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\TableOfContents\TableOfContentsExtension;
use League\CommonMark\Node\Block\Document;
use PHPUnit\Framework\Attributes\DataProvider;
use PomoDocs\CommonMark\Alert\AlertExtension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\Messages\MessageProvider;
use SilverStripe\UserDocs\Markdown\LinkFixerEventHandler;
use SilverStripe\UserDocs\Markdown\MarkdownEnvironmentFactory;
use SilverStripe\UserDocs\Markdown\TableOfContentsEventHandler;
use SilverStripe\UserDocs\Tests\Markdown\MarkdownEnvironmentFactoryTest\RecordingLinkFixerEventHandler;
use SilverStripe\UserDocs\Tests\Markdown\MarkdownEnvironmentFactoryTest\RecordingTableOfContentsEventHandler;

class MarkdownEnvironmentFactoryTest extends SapphireTest
{
    protected function setUp(): void
    {
        parent::setUp();
        // Replace the event handlers so tests can see which ones are added without running them
        Injector::inst()->load([
            TableOfContentsEventHandler::class => ['class' => RecordingTableOfContentsEventHandler::class],
            LinkFixerEventHandler::class => ['class' => RecordingLinkFixerEventHandler::class],
        ]);
        RecordingTableOfContentsEventHandler::$events = [];
        RecordingLinkFixerEventHandler::$events = [];
    }

    public function testCreateSkipsNullExtensions(): void
    {
        // Setting an extension to null in YAML is how projects remove one
        $environment = (new MarkdownEnvironmentFactory())->create(Environment::class, [
            'extensions' => [
                'CommonMarkCoreExtension' => new CommonMarkCoreExtension(),
                'RemovedExtension' => null,
            ],
        ]);

        $this->assertCount(1, $environment->getExtensions());
    }

    public function testCreateLocalisesAlertLabels(): void
    {
        $messageProvider = $this->createMock(MessageProvider::class);
        $messageProvider->method('translate')
            ->willReturnCallback(fn (string $entity, string $default) => "translated $default");
        Injector::inst()->registerService($messageProvider, MessageProvider::class);

        $environment = (new MarkdownEnvironmentFactory())->create(Environment::class, [
            'config' => ['alert' => ['icons' => ['active' => false]]],
            'extensions' => [new CommonMarkCoreExtension(), new AlertExtension()],
        ]);

        $this->assertSame([
            'note' => 'translated Note',
            'tip' => 'translated Tip',
            'important' => 'translated Important',
            'warning' => 'translated Warning',
            'caution' => 'translated Caution',
        ], $environment->getConfiguration()->get('alert/labels'));
        $this->assertFalse($environment->getConfiguration()->get('alert/icons/active'));
    }

    public static function provideCreateAddsTableOfContentsEventHandler(): array
    {
        return [
            'with table of contents extension' => [
                'extensions' => [
                    new CommonMarkCoreExtension(),
                    new HeadingPermalinkExtension(),
                    new TableOfContentsExtension(),
                ],
                'expectedEventCount' => 1,
            ],
            'without table of contents extension' => [
                'extensions' => [new CommonMarkCoreExtension()],
                'expectedEventCount' => 0,
            ],
        ];
    }

    #[DataProvider('provideCreateAddsTableOfContentsEventHandler')]
    public function testCreateAddsTableOfContentsEventHandler(array $extensions, int $expectedEventCount): void
    {
        $environment = (new MarkdownEnvironmentFactory())->create(Environment::class, ['extensions' => $extensions]);

        $this->dispatchDocumentParsed($environment);

        $this->assertCount($expectedEventCount, RecordingTableOfContentsEventHandler::$events);
    }

    public function testCreateAddsLinkFixerEventHandler(): void
    {
        $environment = (new MarkdownEnvironmentFactory())->create(Environment::class, [
            'extensions' => [new CommonMarkCoreExtension()],
        ]);

        $event = $this->dispatchDocumentParsed($environment);

        $this->assertSame([$event], RecordingLinkFixerEventHandler::$events);
    }

    /**
     * Send a DocumentParsedEvent to every listener the environment has for it
     */
    private function dispatchDocumentParsed(Environment $environment): DocumentParsedEvent
    {
        $event = new DocumentParsedEvent(new Document());
        foreach ($environment->getListenersForEvent($event) as $listener) {
            $listener($event);
        }
        return $event;
    }
}
