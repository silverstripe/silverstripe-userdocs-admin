<?php

namespace SilverStripe\UserDocs\Markdown;

use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\Config\ConfigurationAwareInterface;
use League\Config\ConfigurationInterface;
use SilverStripe\Core\Injector\Injectable;

/**
 * Adds the heading permalink ID prefix to custom IDs (e.g. `{#my-id}`) on elements other than headings,
 * so that they can't clash with IDs in the CMS.
 * The HeadingPermalinkExtension already adds the prefix to heading IDs.
 */
class IdPrefixEventHandler implements ConfigurationAwareInterface
{
    use Injectable;

    private ConfigurationInterface $config;

    public function onDocumentParsed(DocumentParsedEvent $event): void
    {
        $prefix = (string) $this->config->get('heading_permalink/id_prefix');
        if ($prefix === '') {
            return;
        }
        $prefix .= '-';
        $applyToHeading = (bool) $this->config->get('heading_permalink/apply_id_to_heading');
        $minLevel = (int) $this->config->get('heading_permalink/min_heading_level');
        $maxLevel = (int) $this->config->get('heading_permalink/max_heading_level');

        foreach ($event->getDocument()->iterator() as $node) {
            if (!$node->data->has('attributes/id')) {
                continue;
            }
            if (
                $applyToHeading
                && $node instanceof Heading
                && $node->getLevel() >= $minLevel
                && $node->getLevel() <= $maxLevel
            ) {
                continue;
            }
            $node->data->set('attributes/id', $prefix . $node->data->get('attributes/id'));
        }
    }

    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->config = $configuration;
    }
}
