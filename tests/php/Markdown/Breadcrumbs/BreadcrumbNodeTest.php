<?php

namespace SilverStripe\UserDocs\Tests\Markdown\Breadcrumbs;

use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserDocs\Markdown\Breadcrumbs\BreadcrumbNode;

class BreadcrumbNodeTest extends SapphireTest
{
    private const BASE_URL = '/admin/user-docs/docs';

    /**
     * Only the keys from UserDocsManifest that BreadcrumbNode uses
     */
    private static function localisedData(): array
    {
        return [
            'getting_started' => [
                'title' => 'Start here',
                'hasContent' => true,
                'slug' => 'getting_started',
                'parentSlug' => null,
            ],
            'getting_started/first_page' => [
                'title' => 'First page',
                'hasContent' => true,
                'slug' => 'getting_started/first_page',
                'parentSlug' => 'getting_started',
            ],
            'getting_started/nested_section' => [
                'title' => 'Nested section',
                'hasContent' => true,
                'slug' => 'getting_started/nested_section',
                'parentSlug' => 'getting_started',
            ],
            'getting_started/nested_section/child_page' => [
                'title' => 'Child page',
                'hasContent' => true,
                'slug' => 'getting_started/nested_section/child_page',
                'parentSlug' => 'getting_started/nested_section',
            ],
            'no_index' => [
                'title' => 'No index',
                'hasContent' => false,
                'slug' => 'no_index',
                'parentSlug' => null,
            ],
            'no_index/some_page' => [
                'title' => 'Some page',
                'hasContent' => true,
                'slug' => 'no_index/some_page',
                'parentSlug' => 'no_index',
            ],
        ];
    }

    public static function provideGetCrumbs(): array
    {
        return [
            'root has no crumbs' => [
                'currentSlug' => 'getting_started',
                'expected' => [],
            ],
            'page in root' => [
                'currentSlug' => 'getting_started/first_page',
                'expected' => [
                    [
                        'title' => 'Start here',
                        'href' => '/admin/user-docs/docs/getting_started',
                    ],
                ],
            ],
            'index of nested section' => [
                'currentSlug' => 'getting_started/nested_section',
                'expected' => [
                    [
                        'title' => 'Start here',
                        'href' => '/admin/user-docs/docs/getting_started',
                    ],
                ],
            ],
            'page in nested section' => [
                'currentSlug' => 'getting_started/nested_section/child_page',
                'expected' => [
                    [
                        'title' => 'Start here',
                        'href' => '/admin/user-docs/docs/getting_started',
                    ],
                    [
                        'title' => 'Nested section',
                        'href' => '/admin/user-docs/docs/getting_started/nested_section',
                    ],
                ],
            ],
            'ancestor without content has no link' => [
                'currentSlug' => 'no_index/some_page',
                'expected' => [
                    [
                        'title' => 'No index',
                        'href' => null,
                    ],
                ],
            ],
        ];
    }

    #[DataProvider('provideGetCrumbs')]
    public function testGetCrumbs(string $currentSlug, array $expected): void
    {
        $node = new BreadcrumbNode(static::BASE_URL, $currentSlug, static::localisedData());

        $this->assertSame($expected, $node->getCrumbs());
    }
}
