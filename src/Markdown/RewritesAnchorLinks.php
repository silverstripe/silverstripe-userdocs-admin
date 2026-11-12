<?php

namespace SilverStripe\UserDocs\Markdown;

use SilverStripe\Core\Convert;

/**
 * Adds the URL of the current doc to anchor links in rendered HTML.
 */
trait RewritesAnchorLinks
{
    /**
     * Add the base URL to the href of each anchor link in the HTML.
     */
    private function addBaseUrlToAnchors(string $html, string $baseUrl): string
    {
        // Use a callback so that "$" and "\" in the base URL aren't used as back references.
        $prefix = Convert::raw2att($baseUrl . '#');
        return preg_replace_callback(
            '/href="#([^"]*)"/i',
            fn (array $matches) => 'href="' . $prefix . $matches[1] . '"',
            $html
        );
    }
}
