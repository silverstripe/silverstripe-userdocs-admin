<?php

namespace SilverStripe\UserDocs\Admin;

use League\CommonMark\ConverterInterface;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\TableOfContents\TableOfContentsExtension;
use League\CommonMark\MarkdownConverter;
use Override;
use PomoDocs\CommonMark\Alert\AlertExtension;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\Hierarchy\MarkedSet;
use SilverStripe\Security\InheritedPermissions;
use SilverStripe\Security\PermissionCheckable;
use SilverStripe\UserDocs\Markdown\BaseUrlMarkdownExtension;
use SilverStripe\UserDocs\Markdown\Breadcrumbs\BreadcrumbsExtension;
use Symfony\Component\Filesystem\Path;

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
     * Paths to directories that contain user documentation.
     *
     * The key is the path and the value is a boolean which sets if the path is included.
     * Each path can be in "module:path" format, or relative to the project root.
     */
    private static array $documentation_roots = [];

    /**
     * Array of paths to CSS files that will be used in the shadow dom
     * where rendered documentation sits.
     */
    private static array $css_files = [
        'silverstripe/userdocs-admin:client/dist/styles/docs.css',
    ];

    private static array $allowed_actions = [
        'treeview',
        'docs',
    ];

    private static array $url_handlers = [
        'treeview/$Slug' => 'treeview',
        'docs/$*' => 'docs',
    ];

    private ?string $currentDocSlug = null;

    #[Override]
    public function init()
    {
        parent::init();
        $manifest = UserDocsManifest::singleton();
        $manifest->init($this->getDocRoots());
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
        // The URL isn't decoded for us, and slugs can contain characters that needed to be encoded
        $this->currentDocSlug = rawurldecode($remaining);
        return $this->getResponseNegotiator()->respond($request);
    }

    /**
     * Get the current doc as HTML, converted from its markdown file.
     *
     * @throws HTTPResponse_Exception If the current doc doesn't exist or has no content
     */
    public function getRenderedDocs(): DBHTMLText
    {
        $data = UserDocsManifest::singleton()->getLocalisedDocData();
        if (!$this->currentDocSlug || !array_key_exists($this->currentDocSlug, $data)) {
            $this->httpError(404);
        }

        $docData = $data[$this->currentDocSlug];
        $filePath = $docData['filePath'];
        if (!$docData['hasContent'] || !file_exists($filePath)) {
            $this->httpError(404);
        }
        /** @var MarkdownConverter $converter */
        $converter = Injector::inst()->create(MarkdownConverter::class . '.userdocs');
        $environment = $converter->getEnvironment();
        $environment->addExtension(BaseUrlMarkdownExtension::create($this->Link('docs'), $docData));
        $environment->addExtension(BreadcrumbsExtension::create($this->Link('docs'), $this->currentDocSlug, $data));
        $markdown = $converter->convert(file_get_contents($filePath));
        return DBField::create_field('HTMLFragment', $markdown->getContent());
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

    /**
     * Get the paths to CSS files for rendered documentation.
     *
     * @return string[]
     */
    public function getCssFiles(): array
    {
        return static::config()->get('css_files');
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
        return str_replace('/', '__SLASH__', $slug);
    }

    private function getCurrentDocSlugFromId(?string $id): string
    {
        if ($id === null) {
            return '';
        }
        // Reverse the replacement from getCurrentDocSlugAsId
        return str_replace('__SLASH__', '/', $id);
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
     * Derived from MarkedSet::markingClasses()
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

    private function getDocRoots(): array
    {
        $roots = [];
        foreach (static::config()->get('documentation_roots') as $dir => $include) {
            if (is_int($dir)) {
                $dir = $include;
                $include = true;
            }
            if (!$include) {
                continue;
            }
            $roots[] = Path::makeAbsolute(ModuleResourceLoader::singleton()->resolvePath($dir), BASE_PATH);
        }
        return $roots;
    }
}
