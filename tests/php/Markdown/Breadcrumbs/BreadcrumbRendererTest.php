<?php

namespace SilverStripe\UserDocs\Tests\Markdown\Breadcrumbs;

use DOMDocument;
use DOMElement;
use DOMXPath;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserDocs\Markdown\Breadcrumbs\BreadcrumbNode;
use SilverStripe\UserDocs\Markdown\Breadcrumbs\BreadcrumbRenderer;

class BreadcrumbRendererTest extends SapphireTest
{
    public function testRender(): void
    {
        $html = $this->render([
            [
                'title' => 'Start here',
                'href' => '/admin/user-docs/docs/getting_started',
            ],
            [
                'title' => 'No index',
                'href' => null,
            ],
            [
                'title' => 'Nested section',
                'href' => '/admin/user-docs/docs/getting_started/nested_section',
            ],
        ]);

        $xpath = $this->loadHtml($html);
        $nav = $xpath->query('/nav')->item(0);
        $this->assertSame('breadcrumbs', $nav->getAttribute('class'));
        $this->assertSame('Breadcrumbs', $nav->getAttribute('aria-label'));
        $this->assertSame(1, $xpath->query('/nav/ol[@class="breadcrumbs__list"]')->length);
        // Crumbs without a URL are a span instead of a link
        $crumbs = array_map(
            fn (DOMElement $crumb) => [
                'tag' => $crumb->tagName,
                'class' => $crumb->getAttribute('class'),
                'href' => $crumb->hasAttribute('href') ? $crumb->getAttribute('href') : null,
                'text' => $crumb->textContent,
            ],
            iterator_to_array($xpath->query('/nav/ol/li[@class="breadcrumbs__list-item"]/*'))
        );
        $this->assertSame([
            [
                'tag' => 'a',
                'class' => 'breadcrumbs__crumb',
                'href' => '/admin/user-docs/docs/getting_started',
                'text' => 'Start here',
            ],
            [
                'tag' => 'span',
                'class' => 'breadcrumbs__crumb',
                'href' => null,
                'text' => 'No index',
            ],
            [
                'tag' => 'a',
                'class' => 'breadcrumbs__crumb',
                'href' => '/admin/user-docs/docs/getting_started/nested_section',
                'text' => 'Nested section',
            ],
        ], $crumbs);
    }

    public function testRenderWithoutCrumbs(): void
    {
        $this->assertSame('', $this->render([]));
    }

    public function testRenderEscapesTitle(): void
    {
        $html = $this->render([
            [
                'title' => 'Q&A <beta>',
                'href' => '/admin/user-docs/docs/q_and_a',
            ],
        ]);

        $this->assertStringContainsString('>Q&amp;A &lt;beta&gt;</a>', $html);
    }

    /**
     * Render a BreadcrumbNode that has these crumbs
     */
    private function render(array $crumbs): string
    {
        $node = $this->createMock(BreadcrumbNode::class);
        $node->method('getCrumbs')->willReturn($crumbs);
        return (string) (new BreadcrumbRenderer())->render(
            $node,
            $this->createMock(ChildNodeRendererInterface::class)
        );
    }

    private function loadHtml(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        $dom->loadXML($html);
        return new DOMXPath($dom);
    }
}
