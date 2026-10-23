<?php

namespace SilverStripe\UserDocs\Markdown;

use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkRenderer;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Xml\XmlNodeRendererInterface;
use League\Config\ConfigurationAwareInterface;
use League\Config\ConfigurationInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injectable;

/**
 * Renders heading permalinks with the base URL added to the anchor links.
 */
class HeadingPermalinkWrapper implements NodeRendererInterface, ConfigurationAwareInterface
{
    use Injectable;

    private HeadingPermalinkRenderer $innerRenderer;

    private string $baseUrl;

    /**
     * @param HeadingPermalinkRenderer $innerRenderer The renderer that renders the permalink before the URL changes
     * @param string $baseUrl The URL of the current doc
     */
    public function __construct(HeadingPermalinkRenderer $innerRenderer, string $baseUrl)
    {
        $this->innerRenderer = $innerRenderer;
        $this->baseUrl = $baseUrl;
    }

    /**
     * {@inheritDoc}
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer)
    {
        $permalinkHtml = (string) $this->innerRenderer->render($node, $childRenderer);

        // Rewrite anchor links to include base URL
        return preg_replace('/href="#([^"]*)"/i', 'href="' . $this->baseUrl . '#$1"', $permalinkHtml);
    }

    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->innerRenderer->setConfiguration($configuration);
    }
}
