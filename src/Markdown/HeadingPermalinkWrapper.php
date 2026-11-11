<?php

namespace SilverStripe\UserDocs\Markdown;

use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkRenderer;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\RawMarkupContainerInterface;
use League\CommonMark\Node\StringContainerHelper;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\Config\ConfigurationAwareInterface;
use League\Config\ConfigurationInterface;
use SilverStripe\Core\Injector\Injectable;

/**
 * Renders heading permalinks with the base URL added to the anchor links.
 */
class HeadingPermalinkWrapper implements NodeRendererInterface, ConfigurationAwareInterface
{
    use Injectable;
    use RewritesAnchorLinks;

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
        $permalink = $this->innerRenderer->render($node, $childRenderer);

        // Give each permalink a label that names its heading, so screen readers don't only announce "#"
        $heading = $node->parent();
        if ($permalink instanceof HtmlElement && $heading) {
            $headingText = trim(StringContainerHelper::getChildText($heading, [RawMarkupContainerInterface::class]));
            $permalink->setAttribute('aria-label', _t(
                __CLASS__ . '.ARIA_LABEL',
                'Permalink: {heading}',
                ['heading' => $headingText]
            ));
        }
        $permalinkHtml = (string) $permalink;

        return $this->addBaseUrlToAnchors($permalinkHtml, $this->baseUrl);
    }

    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->innerRenderer->setConfiguration($configuration);
    }
}
