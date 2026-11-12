<?php

namespace SilverStripe\UserDocs\Markdown;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use SilverStripe\Core\Injector\Injectable;

/**
 * Combines a doc with the content of its extension docs, so the extension content is in the right place
 * when the doc is parsed.
 */
class ExtensionDocAssembler
{
    use Injectable;

    /**
     * Get the metadata for each extension doc of the doc, keyed by the index used in its marker.
     *
     * @param array $docData Metadata for the doc from UserDocsManifest
     */
    public function getExtensionData(array $docData): array
    {
        return array_values($docData['extensions'] ?? []);
    }

    /**
     * Get the markdown of the doc, with the content of each extension doc appended after a marker.
     * Extension docs with no content or with the same file as the doc are skipped.
     *
     * @param array $docData Metadata for the doc from UserDocsManifest
     */
    public function getMarkdown(array $docData): string
    {
        $markdown = file_get_contents($docData['filePath']);
        $frontMatterParser = (new FrontMatterExtension())->getFrontMatterParser();
        foreach ($this->getExtensionData($docData) as $i => $extensionDocData) {
            $extensionFilePath = $extensionDocData['filePath'];
            if (
                $docData['filePath'] === $extensionFilePath
                || !$extensionDocData['hasContent']
                || !file_exists($extensionFilePath)
            ) {
                continue;
            }
            // Only use the markdown content, because front matter is only valid at the start of the file
            $parsedExtension = $frontMatterParser->parse(file_get_contents($extensionFilePath));
            $marker = ExtensionDocMarker::getMarker($i);
            $markdown .= "\n\n$marker\n\n" . $parsedExtension->getContent();
        }
        return $markdown;
    }

    /**
     * Add what the environment needs to put the content of extension docs in the right place,
     * and to remove the markers from getMarkdown().
     *
     * @param array $docData Metadata for the doc from UserDocsManifest
     * @param bool $logWarnings Whether to log warnings about markers that are invalid or in the wrong place
     */
    public function addToEnvironment(
        EnvironmentBuilderInterface $environment,
        array $docData,
        bool $logWarnings = true
    ): void {
        $extensionData = $this->getExtensionData($docData);
        $environment->addExtension(ExtensionPointMarkdownExtension::create($docData, $extensionData, $logWarnings));
        if ($logWarnings) {
            // Check the markers before any content is moved or removed, so no hidden marker is missed
            $environment->addEventListener(
                DocumentParsedEvent::class,
                fn (DocumentParsedEvent $event) => ExtensionDocMarker::warnAboutHiddenMarkers($event, $extensionData),
                1000
            );
        }
        // The markers are only needed while the document is processed, so don't render them
        $environment->addEventListener(DocumentParsedEvent::class, [ExtensionDocMarker::class, 'removeMarkers'], -200);
    }
}
