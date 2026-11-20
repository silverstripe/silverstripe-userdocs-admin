<?php

namespace SilverStripe\UserDocs\Markdown\Breadcrumbs;

use League\CommonMark\Node\Block\AbstractBlock;
use SilverStripe\Core\Injector\Injectable;

/**
 * A markdown node representing the list of breadcrumbs
 */
class BreadcrumbNode extends AbstractBlock
{
    use Injectable;

    private array $crumbs;

    /**
     * @param array $crumbs Breadcrumbs in the format returned by UserDocsManifest::getBreadcrumbs()
     */
    public function __construct(array $crumbs)
    {
        parent::__construct();
        $this->crumbs = $crumbs;
    }

    /**
     * Get the title and URL for each crumb in the breadcrumb list.
     * Doc pages with no content have no URL.
     */
    public function getCrumbs(): array
    {
        return $this->crumbs;
    }
}
