<?php

namespace SilverStripe\UserDocs\Tests\Markdown\Breadcrumbs;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserDocs\Markdown\Breadcrumbs\BreadcrumbsExtension;

class BreadcrumbsExtensionTest extends SapphireTest
{
    public function testOnDocumentParsed(): void
    {
        $crumbs = [
            [
                'title' => 'Start here',
                'href' => '/admin/user-docs/docs/getting_started',
            ],
        ];
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new BreadcrumbsExtension($crumbs));

        $html = (new MarkdownConverter($environment))->convert("# First page\n\nContent.")->getContent();

        // Breadcrumbs are rendered before the rest of the document
        $this->assertStringStartsWith('<nav class="breadcrumbs"', $html);
        $this->assertStringContainsString('href="/admin/user-docs/docs/getting_started"', $html);
        $this->assertStringContainsString("</nav>\n<h1>First page</h1>\n<p>Content.</p>", $html);
    }
}
