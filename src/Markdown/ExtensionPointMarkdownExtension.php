<?php

namespace SilverStripe\UserDocs\Markdown;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Node;
use League\Config\ConfigurationAwareInterface;
use League\Config\ConfigurationInterface;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

/**
 * CommonMark extension which moves content from extension docs to places in the original doc.
 *
 * The original doc declares an extension point with `<!-- userdocs-extension-point: name -->`.
 * Content in an extension doc after an "insert" marker goes in a place in the original doc,
 * until the next "insert" marker or the end of the extension doc:
 * - `<!-- userdocs-insert-at: name -->` puts content in place of the extension point with that name.
 * - `<!-- userdocs-insert-before: #id -->` puts content before the heading with that ID.
 * - `<!-- userdocs-insert-after: #id -->` puts content directly after the heading with that ID.
 * - `<!-- userdocs-insert-end: #id -->` puts content at the end of the section that starts with
 *   the heading with that ID.
 * Content in an extension doc before the first "insert" marker is just appended to the end of the doc.
 * Content for a place that the original doc doesn't have is removed.
 *
 * The content of each extension doc must start with a marker from ExtensionDocMarker::getMarker().
 */
class ExtensionPointMarkdownExtension implements ExtensionInterface, ConfigurationAwareInterface
{
    use Injectable;

    /**
     * Matches an HTML comment that is an extension point or "insert" marker
     */
    private const MARKER_PATTERN
        = '/^<!--\s*userdocs-(?<type>extension-point|insert-(?:at|before|after|end)):(?<name>.*?)-->$/s';

    /**
     * Matches a valid extension point name
     */
    private const NAME_PATTERN = '/^[a-z0-9_-]+$/';

    /**
     * Matches a valid heading ID in an "insert" marker
     */
    private const HEADING_ID_PATTERN = '/^#(?<id>\S+)$/';

    private const TYPE_EXTENSION_POINT = 'extension-point';

    private const TYPE_INSERT_AT = 'insert-at';

    private const TYPE_INSERT_BEFORE = 'insert-before';

    private const TYPE_INSERT_AFTER = 'insert-after';

    private const TYPE_INSERT_END = 'insert-end';

    private array $docData;

    private array $extensionData;

    private bool $logWarnings;

    private ?ConfigurationInterface $config = null;

    /**
     * @param array $docData Metadata for the doc from UserDocsManifest
     * @param array $extensionData Metadata for docs whose content is appended to this doc
     * @param bool $logWarnings Whether to log warnings about invalid markers
     */
    public function __construct(array $docData, array $extensionData = [], bool $logWarnings = true)
    {
        $this->docData = $docData;
        $this->extensionData = $extensionData;
        $this->logWarnings = $logWarnings;
    }

    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->config = $configuration;
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        // Run after other listeners that need the content in its original order, such as the one that updates links.
        // Run after heading IDs are added (priority -100), so headings can be found by their ID.
        // Run before the table of contents is added (priority -150), so it includes the moved content.
        $environment->addEventListener(DocumentParsedEvent::class, [$this, 'onDocumentParsed'], -125);
    }

    public function onDocumentParsed(DocumentParsedEvent $event): void
    {
        $document = $event->getDocument();
        $extensionPoints = [];
        $originalSection = null;
        $chunks = [];
        foreach ($this->getSections($document) as $i => $section) {
            // Only an original doc can have extension points and headings to insert content at.
            // A doc made only of extensions uses an extension as the doc, so it has none.
            if ($i === 0 && !($this->docData['isExtension'] ?? false)) {
                $extensionPoints = $this->getExtensionPoints($section);
                $originalSection = $section;
                continue;
            }
            array_push($chunks, ...$this->getChunks($section));
        }

        $usedHeadingIds = $this->getUsedHeadingIds($chunks);
        $headings = $originalSection ? $this->getHeadings($originalSection, $usedHeadingIds) : [];
        $placeholders = $this->addHeadingPlaceholders($headings, $usedHeadingIds);
        foreach ($chunks as $chunk) {
            $target = null;
            if ($chunk['name'] !== null) {
                if ($chunk['type'] === ExtensionPointMarkdownExtension::TYPE_INSERT_AT) {
                    $target = $extensionPoints[$chunk['name']] ?? null;
                } else {
                    $target = $placeholders[$chunk['type']][$chunk['name']] ?? null;
                }
            }
            foreach ($chunk['nodes'] as $node) {
                if ($target) {
                    $target->insertBefore($node);
                } else {
                    // The original doc can be replaced by a doc that doesn't have this place.
                    // Content after a marker that can't be used is also removed, because it's not meant for the end.
                    $node->detach();
                }
            }
        }

        foreach ($placeholders as $placeholdersForType) {
            foreach ($placeholdersForType as $placeholder) {
                $placeholder->detach();
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
                    $this->warn(
                        $section,
                        "has \"userdocs-{$marker['type']}: {$marker['name']}\", but it isn't an extension"
                    );
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
     * Get the heading IDs that chunks insert content near.
     *
     * @return array Keyed by marker type and then heading ID
     */
    private function getUsedHeadingIds(array $chunks): array
    {
        $usedIds = [];
        foreach ($chunks as $chunk) {
            if ($chunk['type'] !== ExtensionPointMarkdownExtension::TYPE_INSERT_AT && $chunk['name'] !== null) {
                $usedIds[$chunk['type']][$chunk['name']] = true;
            }
        }
        return $usedIds;
    }

    /**
     * Get the headings in the original doc that chunks use, in document order and keyed by ID
     * without the heading ID prefix.
     * Headings can be inside other content such as lists.
     *
     * @param array $usedIds Heading IDs keyed by marker type and then heading ID
     */
    private function getHeadings(array $section, array $usedIds): array
    {
        $allUsedIds = [];
        foreach ($usedIds as $usedIdsForType) {
            $allUsedIds += $usedIdsForType;
        }
        if (empty($allUsedIds)) {
            return [];
        }
        $idPrefix = $this->getHeadingIdPrefix();
        $headings = [];
        foreach ($section['nodes'] as $topLevelNode) {
            foreach ($topLevelNode->iterator() as $node) {
                if (!($node instanceof Heading)) {
                    continue;
                }
                $id = $node->data->get('attributes/id', null);
                if (!is_string($id) || $id === '') {
                    continue;
                }
                if ($idPrefix !== '' && str_starts_with($id, $idPrefix)) {
                    $id = substr($id, strlen($idPrefix));
                }
                if (!isset($allUsedIds[$id])) {
                    continue;
                }
                if (isset($headings[$id])) {
                    $this->warn($section, "has more than one heading with the ID \"$id\"");
                    continue;
                }
                $headings[$id] = $node;
            }
        }
        return $headings;
    }

    /**
     * Get the prefix that the HeadingPermalinkExtension adds to heading IDs, including the "-" separator.
     */
    private function getHeadingIdPrefix(): string
    {
        if (!$this->config || !$this->config->exists('heading_permalink/id_prefix')) {
            return '';
        }
        $idPrefix = (string) $this->config->get('heading_permalink/id_prefix');
        return $idPrefix === '' ? '' : "$idPrefix-";
    }

    /**
     * Add an empty placeholder node to the document for each place near a heading that a chunk needs.
     * Content for that place is inserted before the placeholder, so content from several chunks keeps its order.
     *
     * @param Heading[] $headings Headings in document order, keyed by ID
     * @param array $usedIds Heading IDs keyed by marker type and then heading ID
     * @return array Placeholder nodes keyed by marker type and then heading ID
     */
    private function addHeadingPlaceholders(array $headings, array $usedIds): array
    {
        $placeholders = [];
        // Add end placeholders first, in reverse document order.
        // Then the end of a subsection comes before the end of its parent section,
        // and both come before content that is inserted before the next heading.
        foreach (array_reverse($headings, true) as $id => $heading) {
            if (!isset($usedIds[ExtensionPointMarkdownExtension::TYPE_INSERT_END][$id])) {
                continue;
            }
            $placeholder = $this->createPlaceholder();
            $sectionEnd = $this->getSectionEnd($heading);
            if ($sectionEnd) {
                $sectionEnd->insertBefore($placeholder);
            } else {
                $heading->parent()?->appendChild($placeholder);
            }
            $placeholders[ExtensionPointMarkdownExtension::TYPE_INSERT_END][$id] = $placeholder;
        }
        foreach ($headings as $id => $heading) {
            if (isset($usedIds[ExtensionPointMarkdownExtension::TYPE_INSERT_BEFORE][$id])) {
                $placeholder = $this->createPlaceholder();
                $heading->insertBefore($placeholder);
                $placeholders[ExtensionPointMarkdownExtension::TYPE_INSERT_BEFORE][$id] = $placeholder;
            }
            if (isset($usedIds[ExtensionPointMarkdownExtension::TYPE_INSERT_AFTER][$id])) {
                $placeholder = $this->createPlaceholder();
                $heading->insertAfter($placeholder);
                $placeholders[ExtensionPointMarkdownExtension::TYPE_INSERT_AFTER][$id] = $placeholder;
            }
        }
        return $placeholders;
    }

    /**
     * Get the node that comes directly after the section that starts with the heading.
     * The section ends at the next heading of the same or higher level, or where the extension docs start.
     * Returns null if the section goes to the end of the heading's parent.
     */
    private function getSectionEnd(Heading $heading): ?Node
    {
        $node = $heading->next();
        while ($node) {
            if ($node instanceof Heading && $node->getLevel() <= $heading->getLevel()) {
                return $node;
            }
            if (ExtensionDocMarker::getIndex($node) !== null) {
                return $node;
            }
            $node = $node->next();
        }
        return null;
    }

    private function createPlaceholder(): HtmlBlock
    {
        $placeholder = new HtmlBlock(HtmlBlock::TYPE_7_MISC_ELEMENT);
        $placeholder->setLiteral('');
        return $placeholder;
    }

    /**
     * Get the content in an extension doc for each place it is inserted at.
     * Content before the first "insert" marker isn't included, so it stays where it is.
     * Content after a marker that can't be used has a null name.
     *
     * @return array[] Each chunk has the marker "type", the "name" of the extension point or heading ID,
     * and the top-level "nodes" for it
     */
    private function getChunks(array $section): array
    {
        $chunks = [];
        $current = null;
        foreach ($section['nodes'] as $topLevelNode) {
            $marker = $this->matchMarker($topLevelNode);
            if ($marker && $marker['type'] !== ExtensionPointMarkdownExtension::TYPE_EXTENSION_POINT) {
                if ($current) {
                    $chunks[] = $current;
                }
                $current = [
                    'type' => $marker['type'],
                    'name' => $this->getTargetName($marker, $section),
                    'nodes' => [],
                ];
                continue;
            }
            if ($this->hasNestedInsertMarker($topLevelNode, $section)) {
                if ($current) {
                    $chunks[] = $current;
                }
                $current = ['type' => null, 'name' => null, 'nodes' => []];
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
     * Get the extension point name or heading ID from an "insert" marker, or null if it isn't valid.
     */
    private function getTargetName(array $marker, array $section): ?string
    {
        if ($marker['type'] === ExtensionPointMarkdownExtension::TYPE_INSERT_AT) {
            return $this->isValidName($marker['name'], $section) ? $marker['name'] : null;
        }
        if (preg_match(ExtensionPointMarkdownExtension::HEADING_ID_PATTERN, $marker['name'], $matches)) {
            return $matches['id'];
        }
        $this->warn(
            $section,
            "has \"userdocs-{$marker['type']}: {$marker['name']}\","
            . ' which must have a heading ID that starts with "#"'
        );
        return null;
    }

    /**
     * Check if a top-level node in an extension doc has an "insert" marker inside it.
     * Logs a warning for each marker inside it, because they can't be used there.
     */
    private function hasNestedInsertMarker(Node $topLevelNode, array $section): bool
    {
        $hasNestedInsertMarker = false;
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
                    "has \"userdocs-{$marker['type']}: {$marker['name']}\" inside other content such as a list,"
                    . ' so that content and the content after it is not shown'
                );
                $hasNestedInsertMarker = true;
            }
        }
        return $hasNestedInsertMarker;
    }

    /**
     * Remove all extension point and "insert" markers, because they are only needed to find where content goes.
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
     * Get the type and name of the marker if this node is only an extension point or "insert" marker.
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
        if (!$this->logWarnings) {
            return;
        }
        $filePath = $section['filePath'] ?? 'an unknown file';
        Injector::inst()->get(LoggerInterface::class)->warning("Documentation at $filePath $message");
    }
}
