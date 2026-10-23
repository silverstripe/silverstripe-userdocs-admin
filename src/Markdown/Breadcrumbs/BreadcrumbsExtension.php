<?php

namespace SilverStripe\UserDocs\Markdown\Breadcrumbs;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\ExtensionInterface;
use SilverStripe\Core\Injector\Injectable;

/**
 * Commonmark markdown extension that adds breadcrumbs to the top of doc pages
 */
class BreadcrumbsExtension implements ExtensionInterface
{
    use Injectable;

    private string $baseUrl;

    private string $currentSlug;

    private array $localisedData;

    /**
     * @param string $baseUrl The URL that doc slugs are relative to
     * @param string $currentSlug The slug for the current page of documentation
     * @param array $localisedData Localised metadata for all docs from UserDocsManifest
     */
    public function __construct(string $baseUrl, string $currentSlug, array $localisedData)
    {
        $this->baseUrl = $baseUrl;
        $this->currentSlug = $currentSlug;
        $this->localisedData = $localisedData;
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addEventListener(DocumentParsedEvent::class, [$this, 'onDocumentParsed']);
        $environment->addRenderer(BreadcrumbNode::class, BreadcrumbRenderer::create());
    }

    /**
     * Add breadcrumbs to the top of the document
     */
    public function onDocumentParsed(DocumentParsedEvent $event): void
    {
        $document = $event->getDocument();
        $document->prependChild(BreadcrumbNode::create($this->baseUrl, $this->currentSlug, $this->localisedData));
    }
}
