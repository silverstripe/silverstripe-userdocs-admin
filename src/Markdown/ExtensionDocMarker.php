<?php

namespace SilverStripe\UserDocs\Markdown;

use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\RawMarkupContainerInterface;
use League\CommonMark\Node\StringContainerInterface;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;

/**
 * An HTML comment that shows where the content of an extension doc starts in the markdown for a doc.
 * The index in the marker is the index of the extension doc in the list of extensions for the doc.
 */
final class ExtensionDocMarker
{
    /**
     * Matches a marker from getMarker()
     */
    public const PATTERN = '/<!-- UserDocsAdmin__userdocs-extension:(?<index>\S+) -->/';

    /**
     * Get the marker to put in the markdown before the content of an extension doc.
     */
    public static function getMarker(string|int $index): string
    {
        return "<!-- UserDocsAdmin__userdocs-extension:$index -->";
    }

    /**
     * Get the extension index if this node is only a marker.
     */
    public static function getIndex(Node $node): ?string
    {
        if (!($node instanceof HtmlBlock)) {
            return null;
        }
        if (!preg_match(ExtensionDocMarker::PATTERN, $node->getLiteral(), $matches)) {
            return null;
        }
        if (trim($node->getLiteral()) !== $matches[0]) {
            return null;
        }
        return $matches['index'];
    }

    /**
     * Log a warning for each marker that is part of other content, because then the content of that
     * extension doc can't be found.
     * Use this from a listener that runs before any listener that moves or removes content,
     * so that every marker is checked.
     *
     * @param array $extensionData Metadata for each extension doc, keyed by the index in its marker
     */
    public static function warnAboutHiddenMarkers(DocumentParsedEvent $event, array $extensionData = []): void
    {
        foreach ($event->getDocument()->iterator() as $node) {
            if (ExtensionDocMarker::getIndex($node) === null) {
                ExtensionDocMarker::warnIfMarkerIsInside($node, $extensionData);
            }
        }
    }

    /**
     * Remove all markers from the parsed document, so they aren't rendered.
     * Use this as a listener that runs after all listeners that use the markers.
     */
    public static function removeMarkers(DocumentParsedEvent $event): void
    {
        $markers = [];
        foreach ($event->getDocument()->iterator() as $node) {
            if (ExtensionDocMarker::getIndex($node) !== null) {
                $markers[] = $node;
            }
        }
        foreach ($markers as $marker) {
            $marker->detach();
        }
    }

    /**
     * Log a warning if a marker is part of other content.
     * This happens when content before the marker has an unclosed code block or HTML block.
     */
    private static function warnIfMarkerIsInside(Node $node, array $extensionData): void
    {
        if (!($node instanceof StringContainerInterface) && !($node instanceof RawMarkupContainerInterface)) {
            return;
        }
        if (!preg_match_all(ExtensionDocMarker::PATTERN, $node->getLiteral(), $matches)) {
            return;
        }
        foreach ($matches['index'] as $extensionIndex) {
            $filePath = $extensionData[$extensionIndex]['filePath'] ?? $extensionIndex;
            Injector::inst()->get(LoggerInterface::class)->warning(
                "Could not find where the content from '$filePath' starts, so it might be in the wrong place"
                . ' and its links and images might not work.'
                . ' Check the content before it for an unclosed code block or HTML block.'
            );
        }
    }
}
