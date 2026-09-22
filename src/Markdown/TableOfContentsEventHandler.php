<?php

namespace SilverStripe\UserDocs\Markdown;

use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\TableOfContents\Node\TableOfContentsPlaceholder;
use League\CommonMark\Extension\TableOfContents\TableOfContentsBuilder;
use League\CommonMark\Node\NodeIterator;
use League\Config\ConfigurationAwareInterface;
use League\Config\ConfigurationInterface;
use SilverStripe\Core\Injector\Injectable;

/**
 * Adds a table of contents placeholder to documents after the first level one heading.
 */
class TableOfContentsEventHandler implements ConfigurationAwareInterface
{
    use Injectable;

    private ConfigurationInterface $config;

    /**
     * Add the table of contents placeholder to the parsed document.
     * Does nothing if the table of contents position is not "placeholder".
     */
    public function onDocumentParsed(DocumentParsedEvent $event): void
    {
        $position = $this->config->get('table_of_contents/position');
        if ($position !== TableOfContentsBuilder::POSITION_PLACEHOLDER) {
            return;
        }

        $document = $event->getDocument();

        $foundLevelOne = false;
        foreach ($document->iterator(NodeIterator::FLAG_BLOCKS_ONLY) as $node) {
            if (!($node instanceof Heading) || $node->getLevel() > 1) {
                continue;
            }
            $foundLevelOne = true;
            $node->insertAfter(new TableOfContentsPlaceholder());

            // It's technically possible to have multiple level one headings - we don't
            // want to put a ToC above each one.
            break;
        }

        // If for some reason there was no h1, plop the TOC at the top of the page.
        if (!$foundLevelOne) {
            $document->prependChild(new TableOfContentsPlaceholder());
        }
    }

    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->config = $configuration;
    }
}
