<?php

namespace SilverStripe\UserDocs\Admin;

use BadMethodCallException;
use League\CommonMark\MarkdownConverter;
use Normalizer;
use Override;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\i18n\i18n;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\List\PaginatedList;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\UserDocs\Markdown\BaseUrlMarkdownExtension;
use SilverStripe\UserDocs\Search\SearchForm;
use SilverStripe\UserDocs\Search\SearchIndex;

/**
 * CMS admin section which displays user documentation.
 */
class UserDocsAdmin extends LeftAndMain
{
    private static string $url_segment = 'user-docs';

    private static string $menu_title = 'User docs';

    private static string $menu_icon_class = 'font-icon-help-circled';

    private static int $menu_priority = -999;

    private static string|array $required_permission_codes = [
        'CMS_ACCESS',
    ];

    /**
     * Number of results per page when searching user docs.
     * Set to 0 to disable pagination.
     */
    private static int $search_results_per_page = 20;

    private static array $allowed_actions = [
        'treeview',
        'docs',
        'getSearchForm',
        'initIndex',
    ];

    private static array $url_handlers = [
        'treeview/$Slug' => 'treeview',
        'docs/$*' => 'docs',
        'GET SearchForm' => 'getSearchForm',
    ];

    private static array $casting = [
        'SearchQueryRecommendationLink' => 'HTMLText',
    ];

    private ?string $currentDocSlug = null;

    #[Override]
    public function init()
    {
        parent::init();
        $manifest = UserDocsManifest::singleton();
        $manifest->init();
    }

    /**
     * Action that renders out the HTML markup for the jstree list of links for documentation pages.
     */
    public function treeview(): DBHTMLText
    {
        $slug = rawurldecode($this->getCurrentDocSlugFromId($this->getRequest()->param('Slug')));
        if ($slug) {
            $this->setCurrentDocSlug($slug);
        }
        return $this->renderWith($this->getTemplatesWithSuffix('_TreeView'));
    }

    /**
     * Action which displays the documentation page for the slug in the remaining URL.
     */
    public function docs(HTTPRequest $request): HTTPResponse
    {
        // Get the slug and tell the request we've used up all parts of the URL
        // If we don't do this, the Director assumes there's still more actions
        // to be done and will end up returning an error response.
        $remaining = $request->remaining();
        $request->shift(substr_count($remaining, '/') + 1);
        // The URL isn't decoded for us, and slugs can contain characters that needed to be encoded.
        $slug = rawurldecode($remaining);
        // Slugs are in the composed (NFC) form, so the URL must be too.
        $this->currentDocSlug = Normalizer::normalize($slug, Normalizer::FORM_C) ?: $slug;
        return $this->getResponseNegotiator()->respond($request);
    }

    /**
     * Get the current doc as HTML, converted from its markdown file.
     *
     * @throws HTTPResponse_Exception If the current doc doesn't exist or has no content
     */
    public function getRenderedDocs(): DBHTMLText
    {
        $html = $this->currentDocSlug ? $this->renderDoc($this->currentDocSlug) : null;
        if ($html === null) {
            $this->httpError(404);
        }
        return DBField::create_field('HTMLFragment', $html);
    }

    /**
     * Get the doc for the given slug as HTML, converted from its markdown file.
     *
     * @return string|null The HTML, or null if the doc doesn't exist or has no content
     */
    public function renderDoc(string $slug): ?string
    {
        $docData = UserDocsManifest::singleton()->getLocalisedDocData()[$slug] ?? null;
        if (!$docData || !$docData['hasContent'] || !file_exists($docData['filePath'])) {
            return null;
        }
        /** @var MarkdownConverter $converter */
        $converter = Injector::inst()->create(MarkdownConverter::class . '.userdocs');
        $converter->getEnvironment()->addExtension(BaseUrlMarkdownExtension::create($this->Link('docs'), $docData));
        return $converter->convert(file_get_contents($docData['filePath']))->getContent();
    }

    /**
     * Get the language of the current doc as a value for the HTML lang attribute.
     * This can be different from the CMS language when a doc has no translation.
     */
    public function getCurrentDocLang(): ?string
    {
        if (!$this->currentDocSlug) {
            return null;
        }
        $docData = UserDocsManifest::singleton()->getLocalisedDocData()[$this->currentDocSlug] ?? null;
        if (!$docData) {
            return null;
        }
        return i18n::convert_rfc1766($docData['locale']);
    }

    public function setCurrentDocSlug(?string $slug): static
    {
        $this->currentDocSlug = $slug;
        return $this;
    }

    public function getCurrentDocSlug(): ?string
    {
        return $this->currentDocSlug;
    }

    /**
     * Get the URL of the doc with the given slug.
     */
    public function getDocLink(string $slug): string
    {
        return Controller::join_links($this->Link('docs'), UserDocsManifest::encodeSlug($slug));
    }

    /**
     * Get a doc slug in a format that is safe to use as an ID in a URL.
     *
     * @param string|null $slug The slug to convert. If null, the current doc slug is used.
     */
    public function getCurrentDocSlugAsId(?string $slug = null): ?string
    {
        if ($slug === null) {
            $slug = $this->currentDocSlug;
        }
        if ($slug === null) {
            return $slug;
        }
        // The slug will be included in the URL using rawurlencode,
        // but some Apache configurations won't allow an encoded
        // slash, so we have to replace it with something else first.
        // Dots are replaced so that the URL isn't read as having a file extension.
        return str_replace(['/', '.'], ['__SLASH__', '__DOT__'], $slug);
    }

    /**
     * Returns a Form for record searching for use in templates.
     */
    public function getSearchForm(): SearchForm
    {
        $form = SearchForm::create($this);
        // Allow decorators to modify the form
        $this->extend('updateSearchForm', $form);
        return $form;
    }

    /**
     * Whether the tree has been filtered in this request or not.
     * The tree is only filtered if at least one search field has a value.
     * Empty or whitespace-only values and values that are not strings are ignored, the same as when searching.
     */
    public function treeIsFiltered(): bool
    {
        foreach ($this->getSearchQuery() as $value) {
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }
        return false;
    }

    private function getCurrentDocSlugFromId(?string $id): string
    {
        if ($id === null) {
            return '';
        }
        // Reverse the replacement from getCurrentDocSlugAsId
        return str_replace(['__SLASH__', '__DOT__'], ['/', '.'], $id);
    }

    /**
     * Get the tree (nested unordered list with links to each doc) of documentation pages
     * for the current locale as HTML.
     */
    public function getTreeFor(): DBHTMLText|string
    {
        $treeData = UserDocsManifest::singleton()->getLocalisedTreeData();
        $isFirstPageAssigned = false;
        $renderedTrees = [];
        foreach ($treeData as $root) {
            $renderedTrees[] = $this->renderWith(
                [static::class . '_SubTree'],
                [
                    'controller' => $this,
                    'node' => $this->getNodeData($root, true, $isFirstPageAssigned),
                    'children' => $this->getRenderableChildren($root, $isFirstPageAssigned),
                ],
            );
        }
        return DBField::create_field('HTMLFragment', implode($renderedTrees));
    }

    /**
     * Get the jstree marking classes for this node.
     * Derived from SilverStripe\ORM\Hierarchy\MarkedSet::markingClasses()
     */
    private function getMarkingClasses(array $node): string
    {
        $classes = [];
        // Set jstree open state, or mark it as a leaf (closed) if there are no children
        if (empty($node['children'])) {
            // No children
            $classes[] = "jstree-leaf closed";
        } elseif (str_starts_with($this->currentDocSlug ?? '', $node['slug'] . '/')) {
            // Open with children
            $classes[] = "jstree-open";
        } else {
            // Closed with children
            $classes[] = "jstree-closed closed";
        }
        return implode(' ', $classes);
    }

    private function getRenderableChildren(array $node, bool &$isFirstPageAssigned): ?array
    {
        if (empty($node['children'])) {
            return null;
        }
        $renderable = [];
        foreach ($node['children'] as $childData) {
            $renderable[] = [
                'node' => $this->getNodeData($childData, false, $isFirstPageAssigned),
                'children' => $this->getRenderableChildren($childData, $isFirstPageAssigned),
            ];
        }
        return $renderable;
    }

    private function getNodeData(array $node, bool $isRoot, bool &$isFirstPageAssigned): array
    {
        $isFirstPage = false;
        if (!$isFirstPageAssigned && $node['hasContent']) {
            $isFirstPageAssigned = true;
            $isFirstPage = true;
        }
        return [
            ...$node,
            'isRoot' => $isRoot,
            'isCurrentPage' => $node['slug'] === $this->currentDocSlug,
            'isFirstPage' => $isFirstPage,
            'markingClasses' => $this->getMarkingClasses($node),
        ];
    }

    /**
     * Return the entire tree as a nested set of ULs
     */
    public function treeAsUL(): DBHTMLText|string
    {
        $html = $this->getTreeFor();
        $this->extend('updateTreeAsUL', $html);
        return $html;
    }

    /**
     * Get the HTML markup of results for the current search terms
     */
    public function getSearchResults(): DBHTMLText|string
    {
        if (!$this->treeIsFiltered()) {
            throw new BadMethodCallException('There was no search performed');
        }
        $query = $this->getSearchQuery();
        if (!$this->searchIsSupported()) {
            $this->httpError(400, 'Searching documentation is not supported on this server');
        }
        // The index can be missing after a flush if the page loads with a search query
        $index = SearchIndex::singleton();
        if (!$index->isBuilt()) {
            $index->init();
        }
        $results = PaginatedList::create(
            ArrayList::create($index->search($query)),
            $this->getRequest()
        );
        $results->setPageLength(static::config()->get('search_results_per_page'));
        return $this->renderWith(
            [static::class . '_SearchResults'],
            ['Results' => $results],
        );
    }

    public function getSearchResultSummary(int $indexId): DBHTMLText|string
    {
        $query = $this->getSearchQuery(SearchForm::DEFAULT_SEARCH_FIELD);
        if (!is_string($query)) {
            $query = '';
        }
        return DBField::create_field(
            'HTMLFragment',
            SearchIndex::singleton()->createSummary($query, $indexId)
        );
    }

    public function initIndex(HTTPRequest $request): HTTPResponse
    {
        if (!$this->searchIsSupported()) {
            $this->jsonError(400, 'Searching documentation is not supported on this server');
        }
        SearchIndex::singleton()->init();
        return $this->jsonSuccess(200, []);
    }

    #[Override]
    public function Breadcrumbs($unlinked = false)
    {
        $crumbs = parent::Breadcrumbs($unlinked);
        if ($this->treeIsFiltered()) {
            $crumbs->add(
                ArrayData::create([
                    'Title' => _t(__CLASS__ . '.SearchResults', 'Search results'),
                    'Link' => false,
                ])
            );
        }
        return $crumbs;
    }

    /**
     * Get the title and link of each ancestor of the doc with the given slug, starting at the root.
     * See UserDocsManifest::getBreadcrumbs().
     */
    public function getBreadcrumbsForSlug(string $slug): array
    {
        return UserDocsManifest::singleton()->getBreadcrumbs($slug, $this->Link('docs'));
    }

    /**
     * Get the search query from the request, or the value of one field in it.
     * If the query is not an array, for example "?q=value", it is treated as an empty array.
     *
     * @param string|null $key The field to get the value of. If null, the whole query is returned.
     * @return string|array The whole query as an array, or the value of the field.
     * A string value is trimmed. The value can be an array, for example from "?q[key][]=value",
     * or empty string if the field is not set.
     */
    public function getSearchQuery(?string $key = null): string|array
    {
        $query = $this->getRequest()->getVar('q');
        $query = is_array($query) ? $query : [];
        if ($key !== null) {
            $value = $query[$key] ?? '';
            if (is_string($value)) {
                $value = trim($value);
            }
            return $value;
        }
        return $query;
    }

    /**
     * Get an HTML link that searches for a suggested search term for the current search terms.
     * Returns null if there are no search terms, they are not a string, or there is no suggestion.
     * Also returns null if the search is filtered by title, because the suggestion doesn't check
     * if it would match any pages with that title.
     */
    public function getSearchQueryRecommendationLink(): ?string
    {
        $terms = $this->getSearchQuery(SearchForm::DEFAULT_SEARCH_FIELD);
        if (!is_string($terms) || $terms === '') {
            return null;
        }
        $title = $this->getSearchQuery('title');
        if (is_string($title) && $title !== '') {
            return null;
        }
        $suggestion = SearchIndex::singleton()->getQueryRecommendation($terms);
        if ($suggestion === null || $suggestion === '') {
            return null;
        }
        $query = http_build_query(['q' => [SearchForm::DEFAULT_SEARCH_FIELD => $suggestion]]);
        $url = Controller::join_links($this->Link(), '?' . $query);
        return '<a href="'
            . Convert::raw2att($url)
            . '" class="search-results__suggestion">'
            . Convert::raw2xml($suggestion)
            . '</a>';
    }

    public function searchIsSupported(): bool
    {
        $index = SearchIndex::singleton();
        return $index->isSupported();
    }
}
