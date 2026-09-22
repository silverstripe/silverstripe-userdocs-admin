<?php

namespace SilverStripe\UserDocs\Tests\Markdown\MarkdownEnvironmentFactoryTest;

use League\CommonMark\Event\DocumentParsedEvent;
use SilverStripe\Dev\TestOnly;
use SilverStripe\UserDocs\Markdown\IdPrefixEventHandler;

/**
 * Records the custom IDs it receives instead of changing the document.
 */
class RecordingIdPrefixEventHandler extends IdPrefixEventHandler implements TestOnly
{
    public static array $ids = [];

    public function onDocumentParsed(DocumentParsedEvent $event): void
    {
        foreach ($event->getDocument()->iterator() as $node) {
            if ($node->data->has('attributes/id')) {
                static::$ids[] = $node->data->get('attributes/id');
            }
        }
    }
}
