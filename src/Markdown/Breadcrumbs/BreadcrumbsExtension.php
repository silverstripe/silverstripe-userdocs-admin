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

    private array $crumbs;

    /**
     * @param array $crumbs Breadcrumbs in the format returned by UserDocsManifest::getBreadcrumbs()
     */
    public function __construct(array $crumbs)
    {
        $this->crumbs = $crumbs;
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
        $document->prependChild(BreadcrumbNode::create($this->crumbs));
    }
}
