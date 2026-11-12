<?php

namespace SilverStripe\UserDocs\Tests\Search\SearchIndexTest;

use SilverStripe\Dev\TestOnly;
use TeamTNT\TNTSearch\Engines\EngineInterface;
use TeamTNT\TNTSearch\Support\Collection;

/**
 * A tntsearch engine that isn't one of the engines provided by tntsearch, and does nothing.
 */
class StubEngine implements EngineInterface, TestOnly
{
    public bool $fuzzy_no_limit = false;

    public function loadConfig(array $config)
    {
    }

    public function createIndex(string $indexName)
    {
    }

    public function updateInfoTable(string $key, $value)
    {
    }

    public function getValueFromInfoTable(string $value)
    {
    }

    public function run()
    {
    }

    public function processDocument(Collection $row)
    {
    }

    public function saveToIndex(Collection $stems, int $docId)
    {
    }

    public function selectIndex(string $indexName)
    {
    }

    public function saveWordlist(Collection $stems)
    {
    }

    public function saveDoclist(array $terms, int $docId)
    {
    }

    public function saveHitList(array $stems, int $docId, array $termsList)
    {
    }

    public function delete(int $documentId)
    {
    }

    public function totalDocumentsInCollection()
    {
    }

    public function getWordFromWordList(string $word)
    {
    }

    public function fuzzySearch(string $keyword)
    {
    }

    public function readDocumentsFromFileSystem()
    {
    }

    public function getAllDocumentsForStrictKeyword(array $word, bool $noLimit)
    {
    }

    public function getAllDocumentsForFuzzyKeyword(array $words, bool $noLimit)
    {
    }

    public function getAllDocumentsForWhereKeywordNot(string $keyword, bool $noLimit)
    {
    }

    public function flushIndex(string $indexName)
    {
    }
}
