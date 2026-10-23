<?php

namespace SilverStripe\UserDocs\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Extension\CommonMark\Node\Inline\AbstractWebResource;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Renderer\Block\ListBlockRenderer;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalink;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkRenderer;
use League\CommonMark\Extension\TableOfContents\Node\TableOfContents;
use League\CommonMark\Extension\TableOfContents\TableOfContentsExtension;
use League\CommonMark\Extension\TableOfContents\TableOfContentsRenderer;
use Locale;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\UserDocs\Admin\UserDocsManifest;
use Symfony\Component\Filesystem\Path;

/**
 * CommonMark extension which changes relative links and image paths in a doc
 * so that they resolve correctly in the CMS.
 */
class BaseUrlMarkdownExtension implements ExtensionInterface
{
    use Injectable;

    private string $baseUrl;

    private array $docData;

    private string $missingImagePath;

    /**
     * @param string $baseUrl The URL that doc slugs are relative to
     * @param array $docData Metadata for the doc from UserDocsManifest
     */
    public function __construct(string $baseUrl, array $docData)
    {
        $this->baseUrl = $baseUrl;
        $this->docData = $docData;
        $this->missingImagePath = ModuleResourceLoader::resourceURL(
            'silverstripe/userdocs-admin:client/images/missing-image-placeholder.png'
        );
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addEventListener(DocumentParsedEvent::class, [$this, 'onDocumentParsed']);
        if (!($environment instanceof Environment)) {
            return;
        }

        $hasTableOfContents = false;
        $hasPermalinkExtension = false;
        $baseUrl = Controller::join_links($this->baseUrl, $this->docData['slug']);
        foreach ($environment->getExtensions() as $extension) {
            if ($extension instanceof TableOfContentsExtension) {
                $hasTableOfContents = true;
            }
            if ($extension instanceof HeadingPermalinkExtension) {
                $hasPermalinkExtension = true;
            }
        }

        if ($hasTableOfContents) {
            // We can't use $environment->getRenderersForClass() to get the existing table of contents renderer
            // because that will initialise the environment which doesn't allow adding new renderers.
            $innerRenderer = new TableOfContentsRenderer(new ListBlockRenderer());
            $environment->addRenderer(
                TableOfContents::class,
                TableOfContentsWrapper::create($innerRenderer, $baseUrl),
                99999
            );
        }

        if ($hasPermalinkExtension) {
            $innerRenderer = new HeadingPermalinkRenderer();
            $environment->addRenderer(
                HeadingPermalink::class,
                HeadingPermalinkWrapper::create($innerRenderer, $baseUrl),
                99999
            );
        }
    }

    /**
     * Update the URLs of all links and images in the parsed document.
     */
    public function onDocumentParsed(DocumentParsedEvent $event): void
    {
        $document = $event->getDocument();

        // Find all Link and Image nodes in the document
        foreach ($document->iterator() as $node) {
            if ($node instanceof Link || $node instanceof Image) {
                $this->updateUrl($node);
            }
        }
    }

    private function updateUrl(AbstractWebResource $node): void
    {
        $url = $node->getUrl();

        // Skip empty URLs or URLs with a protocol (http://, https://, ftp://, //, mailto:)
        if (empty($url) || preg_match('/^(https?:|ftp:)?\/\/|mailto:/i', $url)) {
            return;
        }

        if ($node instanceof Image) {
            $missingResourcePath = null;
            // Make sure localised docs can use images from fallback locales
            foreach (UserDocsManifest::getLocalePriority() as $locale) {
                $moduleResourcePath = $this->getModuleResourcePath($locale, $url);
                $imagePath = ModuleResourceLoader::resourcePath($moduleResourcePath);
                if ($imagePath && file_exists(Path::join(BASE_PATH, $imagePath))) {
                    $node->setUrl(ModuleResourceLoader::resourceURL($moduleResourcePath));
                    return;
                }
                if ($locale === $this->docData['locale']) {
                    $missingResourcePath = $moduleResourcePath;
                }
            }
            $node->setUrl($this->missingImagePath);
            if (!$missingResourcePath) {
                $missingResourcePath = $this->getModuleResourcePath($this->docData['locale'], $url);
            }
            Injector::inst()->get(LoggerInterface::class)->warning("Could not find image '$missingResourcePath'");
            return;
        }

        $urlParts = [$this->baseUrl];
        if (!str_starts_with($url, '/')) {
            $urlParts[] = $this->docData['slug'];
            if (!str_starts_with($url, '#') && !$this->docData['isIndex']) {
                $urlParts[] = '..';
            }
        }
        $urlParts[] = $url;

        $node->setUrl(Controller::join_links(...$urlParts));
    }

    /**
     * Get the module resource path for a URL relative to the current doc,
     * as if the doc was in the folder for the given locale.
     */
    private function getModuleResourcePath(string $locale, string $url): string
    {
        // Note that we _must_ use Symfony's Path class here to resolve path traversal,
        // because otherwise Silverstripe's Path class will throw an exception while
        // resolving the resource path.
        // It's okay to deal with here because at this stage there's no chance of
        // traversing beyond the project root.
        $relativePath = Path::canonicalize(Controller::join_links([
            $this->docData['rootPathInModule'],
            $locale,
            $this->docData['pathFromLocale'],
            '..',
            $url,
        ]));
        return $this->docData['module'] . ':' . $relativePath;
    }
}
