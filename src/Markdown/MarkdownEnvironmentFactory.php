<?php

namespace SilverStripe\UserDocs\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\TableOfContents\TableOfContentsExtension;
use PomoDocs\CommonMark\Alert\AlertExtension;
use SilverStripe\Core\Injector\Factory;

/**
 * Creates a CommonMark environment for rendering user documentation.
 */
class MarkdownEnvironmentFactory implements Factory
{
    /**
     * Create a CommonMark environment with the given configuration and extensions.
     *
     * @param array $params Must have an "extensions" key with an array of extension instances.
     * Can have a "config" key with an array of CommonMark configuration.
     */
    public function create(string $service, array $params = []): Environment
    {
        $config = $params['config'] ?? [];
        // Add localised alert text if AlertExtension is being added.
        $extensions = array_filter($params['extensions']);
        foreach ($extensions as $extension) {
            if ($extension instanceof AlertExtension) {
                $config['alert']['labels'] = [
                    'note' => _t(__CLASS__ . '.alert_note', 'Note'),
                    'tip' => _t(__CLASS__ . '.alert_tip', 'Tip'),
                    'important' => _t(__CLASS__ . '.alert_important', 'Important'),
                    'warning' => _t(__CLASS__ . '.alert_warning', 'Warning'),
                    'caution' => _t(__CLASS__ . '.alert_caution', 'Caution'),
                ];
            }
        }

        $environment = new Environment($config);
        $hasTableOfContents = false;
        foreach ($extensions as $extension) {
            $environment->addExtension($extension);
            if ($extension instanceof TableOfContentsExtension) {
                $hasTableOfContents = true;
            }
        }
        if ($hasTableOfContents) {
            // Add a table of contents placeholder after the first h1 in any document, so that the TOC is
            // rendered below the page title instead of above it.
            $environment->addEventListener(
                DocumentParsedEvent::class,
                [TableOfContentsEventHandler::create(), 'onDocumentParsed']
            );
        }

        $environment->addEventListener(
            DocumentParsedEvent::class,
            [LinkFixerEventHandler::create(), 'onDocumentParsed']
        );

        return $environment;
    }
}
