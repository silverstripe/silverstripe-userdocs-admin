<?php

namespace SilverStripe\UserDocs\Markdown;

use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\UserDocs\Admin\UserDocsManifest;

/**
 * Fixes relative links to markdown files by turning them into the correct slug.
 */
class LinkFixerEventHandler
{
    use Injectable;

    /**
     * Update the URLs of all relative markdown links in the parsed document.
     */
    public function onDocumentParsed(DocumentParsedEvent $event): void
    {
        foreach ($event->getDocument()->iterator() as $node) {
            if ($node instanceof Link) {
                $url = $node->getUrl();

                // Skip empty URLs or URLs with a protocol (http://, https://, ftp://, //, mailto:)
                if (empty($url) || preg_match('/^(https?:|ftp:)?\/\/|mailto:/i', $url)) {
                    continue;
                }

                if (preg_match('@\.md(#[^/]*)?$@', $url, $matches)) {
                    // Convert file reference to slug
                    $url = UserDocsManifest::singleton()->getSlugForPath($url);
                    // Add back anchor if present
                    if ($matches[1] ?? null) {
                        $url .= $matches[1];
                    }
                    $node->setUrl($url);
                }
            }
        }
    }
}
