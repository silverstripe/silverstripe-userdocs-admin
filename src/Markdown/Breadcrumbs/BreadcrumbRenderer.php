<?php

namespace SilverStripe\UserDocs\Markdown\Breadcrumbs;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Util\Xml;
use SilverStripe\Core\Injector\Injectable;
use Stringable;

/**
 * Renders BreadcrumbNode as a nav with an ordered list of links (and spans for pages with no content)
 */
class BreadcrumbRenderer implements NodeRendererInterface
{
    use Injectable;

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): Stringable|string|null
    {
        BreadcrumbNode::assertInstanceOf($node);
        $crumbData = $node->getCrumbs();

        if (empty($crumbData)) {
            return '';
        }

        $crumbHtml = [];
        foreach ($crumbData as $crumbDatum) {
            $crumbAttrs = ['class' => 'breadcrumbs__crumb'];
            $crumbElement = 'span';
            if ($crumbDatum['href']) {
                $crumbAttrs['href'] = $crumbDatum['href'];
                $crumbElement = 'a';
            }
            $crumb = new HtmlElement($crumbElement, $crumbAttrs, Xml::escape($crumbDatum['title']), false);
            $crumbHtml[] = new HtmlElement('li', ['class' => 'breadcrumbs__list-item'], $crumb, false);
        }

        $ol = new HtmlElement('ol', ['class' => 'breadcrumbs__list'], $crumbHtml, false);
        $containerAttrs = [
            'class' => 'breadcrumbs',
            'aria-label' => _t(__CLASS__ . '.Breadcrumbs', 'Breadcrumbs'),
        ];
        return new HtmlElement('nav', $containerAttrs, $ol, false);
    }
}
