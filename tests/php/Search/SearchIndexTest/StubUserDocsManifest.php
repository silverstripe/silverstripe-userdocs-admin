<?php

namespace SilverStripe\UserDocs\Tests\Search\SearchIndexTest;

use SilverStripe\Dev\TestOnly;
use SilverStripe\UserDocs\Admin\UserDocsManifest;

/**
 * Provides doc data for the markdown files in this directory without scanning the project.
 */
class StubUserDocsManifest extends UserDocsManifest implements TestOnly
{
    /**
     * @param array $extraDocData Doc data for more pages, keyed by locale and then by slug
     * @param array $localisedDocData Doc data keyed by slug that replaces the data getLocalisedDocData() finds
     */
    public function __construct(private array $extraDocData = [], private array $localisedDocData = [])
    {
    }

    /**
     * Use the first locale in the locale priority that has the page.
     */
    public function getLocalisedDocData(): array
    {
        $result = [];
        foreach (static::getLocalePriority() as $locale) {
            foreach ($this->getDocData($locale) as $slug => $docDatum) {
                $result[$slug] ??= $docDatum;
            }
        }
        return array_replace($result, $this->localisedDocData);
    }

    public function getDocData(?string $locale = null): array
    {
        $titles = [
            'formatting' => 'Formatting',
            'loops' => 'Loops',
            'other' => 'Miscellaneous',
            'template_syntax' => 'Template syntax',
        ];
        $docData = [];
        foreach ($titles as $slug => $title) {
            $docData[$slug] = [
                'title' => $title,
                'hasContent' => true,
                'filePath' => __DIR__ . "/$slug.md",
                'slug' => $slug,
            ];
        }
        $docData = array_replace_recursive(['en' => $docData], $this->extraDocData);
        if ($locale !== null) {
            return $docData[$locale] ?? [];
        }
        return $docData;
    }
}
