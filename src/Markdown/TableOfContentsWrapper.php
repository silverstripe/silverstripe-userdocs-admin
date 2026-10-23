<?php

namespace SilverStripe\UserDocs\Markdown;

use League\CommonMark\Extension\TableOfContents\TableOfContentsRenderer;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Xml\XmlNodeRendererInterface;
use SilverStripe\Core\Injector\Injectable;

/**
 * Renders the table of contents in a nav element with a heading,
 * and adds the base URL to the anchor links.
 */
class TableOfContentsWrapper implements NodeRendererInterface
{
    use Injectable;

    private TableOfContentsRenderer $innerRenderer;

    private string $baseUrl;

    /**
     * @param TableOfContentsRenderer $innerRenderer The renderer that renders the list before the URLs change
     * @param string $baseUrl The URL of the current doc
     */
    public function __construct(TableOfContentsRenderer $innerRenderer, string $baseUrl)
    {
        $this->innerRenderer = $innerRenderer;
        $this->baseUrl = $baseUrl;
    }

    /**
     * {@inheritDoc}
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer)
    {
        $tocListHtml = (string) $this->innerRenderer->render($node, $childRenderer);

        // Rewrite anchor links to include base URL
        $tocListHtml = preg_replace('/href="#([^"]*)"/i', 'href="' . $this->baseUrl . '#$1"', $tocListHtml);

        // Wrap ToC with nav element and heading
        $heading = new HtmlElement(
            'h2',
            ['id' => 'toc-heading', 'class' => 'toc-heading'],
            _t(__CLASS__ . '.on_this_page', 'On this page')
        );
        return new HtmlElement(
            'nav',
            ['class' => 'toc-container', 'aria-labelledby' => 'toc-heading'],
            [$heading, $tocListHtml]
        );
    }
}
