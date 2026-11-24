<?php

namespace SilverStripe\UserDocs\Markdown;

use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use SilverStripe\Core\Injector\Injectable;

/**
 * Renders an ExtensionContentBlock as an element with a lang attribute.
 *
 * The element is a span if the content is only paragraphs in a tight list, because those paragraphs
 * are rendered as text without a p element. Otherwise the element is a div.
 */
class ExtensionContentRenderer implements NodeRendererInterface
{
    use Injectable;

    public const CSS_CLASS = 'userdocs-extension-content';

    /**
     * {@inheritDoc}
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer)
    {
        ExtensionContentBlock::assertInstanceOf($node);

        $attributes = [
            'class' => ExtensionContentRenderer::CSS_CLASS,
            'lang' => $node->getLang(),
        ];
        $tagName = $this->hasOnlyText($node) ? 'span' : 'div';
        $contents = $childRenderer->renderNodes($node->children());
        // Put block content on its own lines, as other block renderers do
        if ($tagName === 'div') {
            $separator = $childRenderer->getBlockSeparator();
            $contents = $separator . $contents . $separator;
        }
        return new HtmlElement($tagName, $attributes, $contents);
    }

    /**
     * Check if the block only has paragraphs which are rendered as text without a p element.
     */
    private function hasOnlyText(ExtensionContentBlock $node): bool
    {
        if (!$node->isTight() || !$node->hasChildren()) {
            return false;
        }
        foreach ($node->children() as $child) {
            if (!($child instanceof Paragraph)) {
                return false;
            }
        }
        return true;
    }
}
