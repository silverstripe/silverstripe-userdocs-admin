<?php

namespace SilverStripe\UserDocs\Markdown\Breadcrumbs;

use League\CommonMark\Node\Block\AbstractBlock;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injectable;

/**
 * A markdown node representing the list of breadcrumbs
 */
class BreadcrumbNode extends AbstractBlock
{
    use Injectable;

    private string $baseUrl;

    private string $currentSlug;

    private array $localisedData;

    /**
     * @param string $baseUrl The URL that doc slugs are relative to
     * @param string $currentSlug The slug for the current page of documentation
     * @param array $localisedData Localised metadata for all docs from UserDocsManifest
     */
    public function __construct(string $baseUrl, string $currentSlug, array $localisedData)
    {
        $this->baseUrl = $baseUrl;
        $this->currentSlug = $currentSlug;
        $this->localisedData = $localisedData;
    }

    /**
     * Get the title and URL for each crumb in the breadcrumb list.
     * Doc pages with no content have no URL.
     */
    public function getCrumbs(): array
    {
        $current = $this->localisedData[$this->currentSlug];
        $crumbs = [];
        do {
            // skip the current page
            if ($current['slug'] !== $this->currentSlug) {
                array_unshift($crumbs, [
                    'title' => $current['title'],
                    'href' => $current['hasContent']
                        ? Controller::join_links($this->baseUrl, $current['slug'])
                        : null,
                ]);
            }
            $current = $this->localisedData[$current['parentSlug']] ?? null;
        } while ($current !== null);
        return $crumbs;
    }
}
