<?php

namespace SilverStripe\UserDocs\Markdown;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListData;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Text;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\i18n\i18n;
use SilverStripe\UserDocs\Admin\UserDocsClassResolver;
use SilverStripe\UserDocs\Admin\UserDocsManifest;

/**
 * Adds a "Related docs" heading and a list of links to the end of a doc.
 *
 * The links go to:
 *   - the doc for the nearest parent class of each class that the doc names
 *   - the parent doc of the doc
 *   - the child docs of the doc
 *   - other docs that name the same classes, except other ancestors and descendants of the doc
 *
 * Set "related_docs: false" in the frontmatter of a doc to leave out the links.
 */
class RelatedDocsExtension implements ExtensionInterface
{
    use Injectable;

    /**
     * The ID of the heading, before the prefix that all heading IDs get.
     * It is reserved in the slug normalizer config, so other headings never get it from their text.
     */
    public const HEADING_ID = 'related-docs';

    /**
     * A key in the data of the heading node, so that other listeners can find the heading
     */
    public const NODE_DATA_KEY = 'userdocs_related_docs';

    private array $docData;

    /**
     * @param array $docData The doc data from UserDocsManifest::getLocalisedDocData() for the doc being rendered
     */
    public function __construct(array $docData)
    {
        $this->docData = $docData;
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        // Run before links (priority 0), IDs (priority -50) and heading permalinks (priority -100) are updated,
        // so that the related docs are updated in the same way as other content.
        $environment->addEventListener(DocumentParsedEvent::class, [$this, 'addRelatedDocs'], 50);
    }

    /**
     * Add the heading and the list of links to the end of the document.
     * Nothing is added if there are no related docs, or if the frontmatter turns them off.
     */
    public function addRelatedDocs(DocumentParsedEvent $event): void
    {
        if (($this->docData['frontMatter']['related_docs'] ?? true) === false) {
            return;
        }
        $allDocData = UserDocsManifest::singleton()->getLocalisedDocData();
        $slugs = $this->getRelatedSlugs($allDocData);
        if (empty($slugs)) {
            return;
        }
        $document = $event->getDocument();

        $heading = new Heading(2);
        $heading->data->set('attributes/id', static::HEADING_ID);
        $heading->data->set(static::NODE_DATA_KEY, true);
        $heading->appendChild(new Text($this->getHeadingText()));
        $document->appendChild($heading);

        $listData = new ListData();
        $listData->type = ListBlock::TYPE_BULLET;
        $listData->bulletChar = '-';
        $listData->markerOffset = 0;
        $listData->padding = 2;
        $list = new ListBlock($listData);
        $list->setTight(true);
        foreach ($slugs as $slug) {
            $list->appendChild($this->createListItem($listData, $allDocData[$slug]));
        }
        $document->appendChild($list);

        // The related docs are after the content of the last extension doc, but they aren't part of it
        foreach ([$heading, $list] as $node) {
            $node->data->set(ExtensionPointMarkdownExtension::NOT_DOC_CONTENT_DATA_KEY, true);
        }
    }

    /**
     * Get the slugs of the related docs in the order they're listed. Only docs with content are included.
     *
     * @param array $allDocData Doc data for every doc, from UserDocsManifest::getLocalisedDocData()
     * @return string[]
     */
    private function getRelatedSlugs(array $allDocData): array
    {
        $manifest = UserDocsManifest::singleton();
        $resolver = UserDocsClassResolver::singleton();
        $slug = (string) $this->docData['slug'];
        $classes = $manifest->getDocClasses($this->docData);
        $related = [];
        // get main page for parent class
        foreach ($classes as $class) {
            $parentClassSlug = $resolver->getSlugForParentClass($class);
            if ($parentClassSlug !== null) {
                $related[] = $parentClassSlug;
            }
        }
        // get parent page
        if ($this->docData['parentSlug'] !== null) {
            $related[] = (string) $this->docData['parentSlug'];
        }
        // get child pages
        foreach ($allDocData as $docDatum) {
            if ($docDatum['parentSlug'] === $slug) {
                $related[] = (string) $docDatum['slug'];
            }
        }
        // get related pages for doc classes
        foreach ($classes as $class) {
            foreach ($resolver->getSlugsForClass($class) as $classSlug) {
                if (!str_starts_with($classSlug, "$slug/") && !str_starts_with($slug, "$classSlug/")) {
                    $related[] = $classSlug;
                }
            }
        }
        $related = array_filter(
            array_unique($related),
            fn (string $relatedSlug) => $relatedSlug !== $slug && ($allDocData[$relatedSlug]['hasContent'] ?? false)
        );
        return array_values($related);
    }

    /**
     * Create a list item with a link to the given doc.
     * The link has its own language if the doc it links to isn't in the language of this doc.
     */
    private function createListItem(ListData $listData, array $docDatum): ListItem
    {
        $link = new Link('/' . UserDocsManifest::encodeSlug($docDatum['slug']), $docDatum['title']);
        if ($docDatum['locale'] !== $this->docData['locale']) {
            $link->data->set('attributes/lang', i18n::convert_rfc1766($docDatum['locale']));
        }
        $paragraph = new Paragraph();
        $paragraph->appendChild($link);
        $item = new ListItem($listData);
        $item->appendChild($paragraph);
        return $item;
    }

    /**
     * Get the heading text in the language of the doc, so that it matches the content around it.
     */
    private function getHeadingText(): string
    {
        return i18n::with_locale(
            $this->docData['locale'],
            fn () => _t(__CLASS__ . '.HEADING', 'Related docs')
        );
    }
}
