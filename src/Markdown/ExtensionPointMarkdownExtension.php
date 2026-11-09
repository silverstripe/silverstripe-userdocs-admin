<?php

namespace SilverStripe\UserDocs\Markdown;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Node;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

/**
 * CommonMark extension which moves content from extension docs to the extension points declared in the original doc.
 *
 * The original doc declares an extension point with `<!-- userdocs-extension-point: name -->`.
 * Content in an extension doc after `<!-- userdocs-extends: name -->` goes in place of that extension point,
 * until the next "userdocs-extends" marker or the end of the extension doc.
 * Content in an extension doc before the first "userdocs-extends" marker is just appended to the end of the doc.
 * Content for an extension point that the original doc doesn't have is removed.
 *
 * The content of each extension doc must start with a marker from ExtensionDocMarker::getMarker().
 */
class ExtensionPointMarkdownExtension implements ExtensionInterface
{
    use Injectable;

    /**
     * Matches an HTML comment that is an extension point or "userdocs-extends" marker
     */
    private const MARKER_PATTERN = '/^<!--\s*userdocs-(?<type>extension-point|extends):(?<name>.*?)-->$/s';

    /**
     * Matches a valid extension point name
     */
    private const NAME_PATTERN = '/^[a-z0-9_-]+$/';

    private const TYPE_EXTENSION_POINT = 'extension-point';

    private const TYPE_EXTENDS = 'extends';

    private array $docData;

    private array $extensionData;

    /**
     * @param array $docData Metadata for the doc from UserDocsManifest
     * @param array $extensionData Metadata for docs whose content is appended to this doc
     */
    public function __construct(array $docData, array $extensionData = [])
    {
        $this->docData = $docData;
        $this->extensionData = $extensionData;
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        // Run after other listeners that need the content in its original order, such as the one that updates links.
        // Run before IDs and the table of contents are added, so they include content at extension points.
        $environment->addEventListener(DocumentParsedEvent::class, [$this, 'onDocumentParsed'], -10);
    }

    public function onDocumentParsed(DocumentParsedEvent $event): void
    {
        $document = $event->getDocument();
        // Only the original doc can have extension points
        $sections = $this->getSections($document);
        $originalSection = array_shift($sections);
        $extensionPoints = $this->getExtensionPoints($originalSection);
        $chunks = [];
        foreach ($sections as $section) {
            array_push($chunks, ...$this->getChunks($section));
        }

        foreach ($chunks as $chunk) {
            $extensionPoint = $chunk['name'] === null ? null : ($extensionPoints[$chunk['name']] ?? null);
            foreach ($chunk['nodes'] as $node) {
                if ($extensionPoint) {
                    $extensionPoint->insertBefore($node);
                } else {
                    // The original doc can be replaced by a doc that doesn't have this extension point.
                    // Content after a marker that can't be used is also removed, because it's not meant for the end.
                    $node->detach();
                }
            }
        }

        $this->removeMarkers($document);
    }

    /**
     * Split the top-level nodes of the document into the content of each doc.
     * The first section is the main doc, and each other section is an extension doc.
     *
     * @return array[] Each section has a "filePath" and the "nodes" in it
     */
    private function getSections(Document $document): array
    {
        $sections = [];
        $current = ['filePath' => $this->docData['filePath'] ?? null, 'nodes' => []];
        foreach ($document->children() as $node) {
            $extensionIndex = ExtensionDocMarker::getIndex($node);
            if ($extensionIndex === null) {
                $current['nodes'][] = $node;
                continue;
            }
            $sections[] = $current;
            $current = ['filePath' => $this->extensionData[$extensionIndex]['filePath'] ?? null, 'nodes' => []];
        }
        $sections[] = $current;
        return $sections;
    }

    /**
     * Get the extension point markers in the original doc, keyed by name.
     * Extension points can be inside other content such as lists.
     */
    private function getExtensionPoints(array $section): array
    {
        $extensionPoints = [];
        foreach ($section['nodes'] as $topLevelNode) {
            foreach ($topLevelNode->iterator() as $node) {
                $marker = $this->matchMarker($node);
                if (!$marker) {
                    continue;
                }
                if ($marker['type'] !== ExtensionPointMarkdownExtension::TYPE_EXTENSION_POINT) {
                    $this->warn($section, "has \"userdocs-extends: {$marker['name']}\", but it isn't an extension");
                    continue;
                }
                if (!$this->isValidName($marker['name'], $section)) {
                    continue;
                }
                if (isset($extensionPoints[$marker['name']])) {
                    $this->warn($section, "has more than one \"{$marker['name']}\" extension point");
                    continue;
                }
                $extensionPoints[$marker['name']] = $node;
            }
        }
        return $extensionPoints;
    }

    /**
     * Get the content in an extension doc for each extension point.
     * Content before the first "userdocs-extends" marker isn't included, so it stays where it is.
     * Content after a marker that can't be used has a null name.
     *
     * @return array[] Each chunk has the "name" of the extension point and the top-level "nodes" for it
     */
    private function getChunks(array $section): array
    {
        $chunks = [];
        $current = null;
        foreach ($section['nodes'] as $topLevelNode) {
            $marker = $this->matchMarker($topLevelNode);
            if ($marker && $marker['type'] === ExtensionPointMarkdownExtension::TYPE_EXTENDS) {
                if ($current) {
                    $chunks[] = $current;
                }
                $name = $this->isValidName($marker['name'], $section) ? $marker['name'] : null;
                $current = ['name' => $name, 'nodes' => []];
                continue;
            }
            if ($this->hasNestedExtendsMarker($topLevelNode, $section)) {
                if ($current) {
                    $chunks[] = $current;
                }
                $current = ['name' => null, 'nodes' => []];
            }
            if ($current) {
                $current['nodes'][] = $topLevelNode;
            }
        }
        if ($current) {
            $chunks[] = $current;
        }
        return $chunks;
    }

    /**
     * Check if a top-level node in an extension doc has a "userdocs-extends" marker inside it.
     * Logs a warning for each marker inside it, because they can't be used there.
     */
    private function hasNestedExtendsMarker(Node $topLevelNode, array $section): bool
    {
        $hasNestedExtendsMarker = false;
        foreach ($topLevelNode->iterator() as $node) {
            $marker = $this->matchMarker($node);
            if (!$marker) {
                continue;
            }
            if ($marker['type'] === ExtensionPointMarkdownExtension::TYPE_EXTENSION_POINT) {
                $this->warn(
                    $section,
                    "has the \"{$marker['name']}\" extension point, but only an original doc can have extension points"
                );
            } else {
                $this->warn(
                    $section,
                    "has \"userdocs-extends: {$marker['name']}\" inside other content such as a list,"
                    . ' so that content and the content after it is not shown'
                );
                $hasNestedExtendsMarker = true;
            }
        }
        return $hasNestedExtendsMarker;
    }

    /**
     * Remove all extension point and "userdocs-extends" markers, because they are only needed to find where
     * content goes.
     */
    private function removeMarkers(Document $document): void
    {
        $markers = [];
        foreach ($document->iterator() as $node) {
            if ($this->matchMarker($node)) {
                $markers[] = $node;
            }
        }
        foreach ($markers as $marker) {
            $marker->detach();
        }
    }

    /**
     * Get the type and name of the marker if this node is only an extension point or "userdocs-extends" marker.
     */
    private function matchMarker(Node $node): ?array
    {
        if (!($node instanceof HtmlBlock)) {
            return null;
        }
        if (!preg_match(ExtensionPointMarkdownExtension::MARKER_PATTERN, trim($node->getLiteral()), $matches)) {
            return null;
        }
        return ['type' => $matches['type'], 'name' => trim($matches['name'])];
    }

    private function isValidName(string $name, array $section): bool
    {
        if (preg_match(ExtensionPointMarkdownExtension::NAME_PATTERN, $name)) {
            return true;
        }
        $this->warn(
            $section,
            "has the extension point name \"$name\", which can only have lowercase letters, numbers, \"-\" and \"_\""
        );
        return false;
    }

    private function warn(array $section, string $message): void
    {
        $filePath = $section['filePath'] ?? 'an unknown file';
        Injector::inst()->get(LoggerInterface::class)->warning("Documentation at $filePath $message");
    }
}
