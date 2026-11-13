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
use League\Config\ConfigurationAwareInterface;
use League\Config\ConfigurationInterface;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\UserDocs\Admin\UserDocsImageLocator;
use SilverStripe\UserDocs\Admin\UserDocsManifest;
use Symfony\Component\Filesystem\Path;

/**
 * CommonMark extension which changes relative links and image paths in a doc
 * so that they resolve correctly in the CMS.
 */
class BaseUrlMarkdownExtension implements ExtensionInterface, ConfigurationAwareInterface
{
    use Injectable;

    private string $baseUrl;

    private array $docData;

    private string $imageUrl;

    private string $missingImagePath;

    private ?ConfigurationInterface $config = null;

    /**
     * @param string $baseUrl The URL that doc slugs are relative to
     * @param array $docData Metadata for the doc from UserDocsManifest
     * @param string $imageUrl The URL of the action which sends images from the docs
     */
    public function __construct(string $baseUrl, array $docData, string $imageUrl)
    {
        $this->baseUrl = $baseUrl;
        $this->docData = $docData;
        $this->imageUrl = $imageUrl;
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
        $baseUrl = Controller::join_links($this->baseUrl, UserDocsManifest::encodeSlug($this->docData['slug']));
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

        // Skip empty URLs, URLs with a scheme (e.g. https:, mailto:, tel:) and protocol-relative URLs
        if (empty($url) || preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $url)) {
            return;
        }

        if ($node instanceof Image) {
            $this->updateImageUrl($node, $url);
        } else {
            $this->updateLinkUrl($node, $url);
        }
    }

    /**
     * Point an image at the CMS action which sends images, or at the missing image placeholder.
     */
    private function updateImageUrl(Image $node, string $url): void
    {
        $node->setUrl($this->missingImagePath);
        // CommonMark percent-encodes URLs, but file names on disk aren't encoded
        $url = rawurldecode($url);
        $logger = Injector::inst()->get(LoggerInterface::class);
        $module = $this->docData['module'];
        $rootPathInModule = $this->docData['rootPathInModule'];
        // Locales are a single folder, so the result is the same for all locales
        $pathInRoot = $this->getPathInRoot($this->docData['locale'], $url);
        if ($pathInRoot === '..' || str_starts_with($pathInRoot, '../')) {
            $docPath = Path::join($rootPathInModule, $this->docData['locale'], $this->docData['pathFromLocale']);
            $logger->warning("Image '$url' in '$module:$docPath' is outside the documentation root");
            return;
        }
        $locator = UserDocsImageLocator::singleton();
        $filePath = null;
        $pathInModule = null;
        // Make sure localised docs can use images from fallback locales
        foreach (UserDocsManifest::getLocalePriority() as $locale) {
            $pathInModule = Path::join($rootPathInModule, $this->getPathInRoot($locale, $url));
            $filePath = $locator->getFilePath($module, $rootPathInModule, $pathInModule);
            if ($filePath) {
                break;
            }
        }
        if (!$filePath) {
            $missingPathInModule = Path::join($rootPathInModule, $pathInRoot);
            $logger->warning("Could not find image '$module:$missingPathInModule'");
            return;
        }
        if (!$locator->getMimeType($filePath)) {
            $logger->warning("Image '$module:$pathInModule' is not a supported type of image");
            return;
        }
        $query = http_build_query([
            'module' => $module,
            'path' => $pathInModule,
            'v' => $locator->getHash($filePath),
        ]);
        $node->setUrl($this->imageUrl . '?' . $query);
    }

    /**
     * Make the link URL relative to the base URL of the documentation.
     */
    private function updateLinkUrl(AbstractWebResource $node, string $url): void
    {
        $url = $this->addFragmentPrefix($url);
        $urlParts = [$this->baseUrl];
        if (str_starts_with($url, '#')) {
            $urlParts[] = UserDocsManifest::encodeSlug($this->docData['slug']);
            $urlParts[] = $url;
        } elseif (str_starts_with($url, '/')) {
            $urlParts[] = $url;
        } else {
            // Resolve the link against the doc's folder so the URL holds the real slug, with no ".." in it
            $dir = $this->docData['isIndex'] ? $this->docData['slug'] : dirname($this->docData['slug']);
            // Keep any query string or anchor out of the path so it can't change how the path resolves
            $pathLength = strcspn($url, '?#');
            $urlParts[] = Path::join(UserDocsManifest::encodeSlug($dir), substr($url, 0, $pathLength))
                . substr($url, $pathLength);
        }

        $node->setUrl(Controller::join_links(...$urlParts));
    }

    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->config = $configuration;
    }

    /**
     * Add the heading permalink fragment prefix to the anchor in a URL,
     * so that the anchor matches the prefixed ID in the rendered doc.
     */
    private function addFragmentPrefix(string $url): string
    {
        $hashPos = strpos($url, '#');
        if (
            $hashPos === false
            || $hashPos === strlen($url) - 1
            || !$this->config?->exists('heading_permalink/fragment_prefix')
        ) {
            return $url;
        }
        $prefix = (string) $this->config->get('heading_permalink/fragment_prefix');
        if ($prefix === '') {
            return $url;
        }
        return substr($url, 0, $hashPos + 1) . $prefix . '-' . substr($url, $hashPos + 1);
    }

    /**
     * Get the path relative to the documentation root for a URL relative to the current doc,
     * as if the doc was in the folder for the given locale.
     * The path starts with "../" if the URL points outside the documentation root.
     */
    private function getPathInRoot(string $locale, string $url): string
    {
        // Note that we _must_ use Symfony's Path class here to resolve path traversal,
        // because otherwise Silverstripe's Path class will throw an exception while
        // resolving the path.
        return Path::canonicalize(Controller::join_links([
            $locale,
            $this->docData['pathFromLocale'],
            '..',
            $url,
        ]));
    }
}
