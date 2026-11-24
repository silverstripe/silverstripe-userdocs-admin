<?php

namespace SilverStripe\UserDocs\Markdown;

use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\UserDocs\Admin\UserDocsManifest;

/**
 * Changes links to docs that don't exist into plain text.
 * Docs can link to docs from modules which are not always installed.
 *
 * This must run after the links are changed to the URLs of the docs.
 */
class MissingDocLinkEventHandler
{
    use Injectable;

    private string $baseUrl;

    /**
     * @param string $baseUrl The URL that doc slugs are relative to
     */
    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Replace each link to a missing doc with the content of the link.
     */
    public function onDocumentParsed(DocumentParsedEvent $event): void
    {
        $docData = UserDocsManifest::singleton()->getLocalisedDocData();
        // Collect the links first, because changing the document stops the iterator from working correctly
        $missingLinks = [];
        foreach ($event->getDocument()->iterator() as $node) {
            if ($node instanceof Link && $this->isLinkToMissingDoc($node->getUrl(), $docData)) {
                $missingLinks[] = $node;
            }
        }
        foreach ($missingLinks as $link) {
            foreach ($link->children() as $child) {
                $link->insertBefore($child);
            }
            $link->detach();
        }
    }

    private function isLinkToMissingDoc(string $url, array $docData): bool
    {
        $prefix = $this->baseUrl . '/';
        if (!str_starts_with($url, $prefix)) {
            return false;
        }
        // The URL can have a trailing slash, but slugs don't
        $slug = rtrim(UserDocsManifest::getSlugFromUrlPath(substr($url, strlen($prefix))), '/');
        return !($docData[$slug]['hasContent'] ?? false);
    }
}
