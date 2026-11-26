<?php

namespace SilverStripe\UserDocs\Markdown;

use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\TableOfContents\Node\TableOfContentsPlaceholder;
use League\CommonMark\Extension\TableOfContents\TableOfContentsBuilder;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\NodeIterator;
use League\Config\ConfigurationAwareInterface;
use League\Config\ConfigurationInterface;
use SilverStripe\Core\Injector\Injectable;

/**
 * Adds a table of contents placeholder to documents before the first heading that is in the table of contents.
 * The first level one heading is not used for this, so that the intro of a document comes before the table of contents.
 */
class TableOfContentsEventHandler implements ConfigurationAwareInterface
{
    use Injectable;

    private ConfigurationInterface $config;

    /**
     * Add the table of contents placeholder to the parsed document.
     * Does nothing if the table of contents position is not "placeholder".
     *
     * If no top-level heading is in the table of contents, the placeholder goes after the first level one heading.
     * If there is no level one heading either, the placeholder goes at the top of the document.
     * No placeholder is added if the related docs heading would be the only heading in the table of contents.
     */
    public function onDocumentParsed(DocumentParsedEvent $event): void
    {
        $position = $this->config->get('table_of_contents/position');
        if ($position !== TableOfContentsBuilder::POSITION_PLACEHOLDER) {
            return;
        }

        $minLevel = (int) $this->config->get('table_of_contents/min_heading_level');
        $maxLevel = (int) $this->config->get('table_of_contents/max_heading_level');
        $document = $event->getDocument();
        if (!$this->hasHeadingForTableOfContents($document, $minLevel, $maxLevel)) {
            return;
        }

        // Only check top-level blocks, so the table of contents is never put inside a list or a block quote.
        $firstLevelOne = null;
        foreach ($document->children() as $node) {
            if (!($node instanceof Heading)) {
                continue;
            }
            // The related docs heading is always at the end, so the table of contents never goes before it
            if ($node->data->has(RelatedDocsExtension::NODE_DATA_KEY)) {
                continue;
            }
            $level = $node->getLevel();
            if ($level === 1 && $firstLevelOne === null) {
                $firstLevelOne = $node;
                continue;
            }
            if ($level >= $minLevel && $level <= $maxLevel) {
                $node->insertBefore(new TableOfContentsPlaceholder());
                return;
            }
        }

        if ($firstLevelOne !== null) {
            $firstLevelOne->insertAfter(new TableOfContentsPlaceholder());
            return;
        }

        $document->prependChild(new TableOfContentsPlaceholder());
    }

    /**
     * Check if the document has a heading for the table of contents, other than the related docs heading.
     * Headings that aren't top-level blocks are included, because they're in the table of contents too.
     */
    private function hasHeadingForTableOfContents(Document $document, int $minLevel, int $maxLevel): bool
    {
        foreach ($document->iterator(NodeIterator::FLAG_BLOCKS_ONLY) as $node) {
            if (
                $node instanceof Heading
                && $node->getLevel() >= $minLevel
                && $node->getLevel() <= $maxLevel
                && !$node->data->has(RelatedDocsExtension::NODE_DATA_KEY)
            ) {
                return true;
            }
        }
        return false;
    }

    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->config = $configuration;
    }
}
