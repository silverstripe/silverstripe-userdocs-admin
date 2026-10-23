<?php

namespace SilverStripe\UserDocs\Tests\Markdown\MarkdownEnvironmentFactoryTest;

use League\CommonMark\Event\DocumentParsedEvent;
use SilverStripe\Dev\TestOnly;
use SilverStripe\UserDocs\Markdown\LinkFixerEventHandler;

/**
 * Records the events it receives instead of changing the document.
 */
class RecordingLinkFixerEventHandler extends LinkFixerEventHandler implements TestOnly
{
    public static array $events = [];

    public function onDocumentParsed(DocumentParsedEvent $event): void
    {
        static::$events[] = $event;
    }
}
