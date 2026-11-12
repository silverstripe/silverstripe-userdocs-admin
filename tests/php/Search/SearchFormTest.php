<?php

namespace SilverStripe\UserDocs\Tests\Search;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserDocs\Admin\UserDocsAdmin;
use SilverStripe\UserDocs\Search\SearchForm;

class SearchFormTest extends SapphireTest
{
    private function createSearchForm(): SearchForm
    {
        return SearchForm::create(UserDocsAdmin::create());
    }

    public static function provideIdSuffix(): array
    {
        return [
            'no suffix' => [
                'idSuffix' => '',
                'expectedId' => 'UserDocsAdmin_SearchForm',
            ],
            'with suffix' => [
                'idSuffix' => '_Short-Width1',
                'expectedId' => 'UserDocsAdmin_SearchForm_Short-Width1',
            ],
        ];
    }

    public static function provideIdSuffixInvalid(): array
    {
        return [
            'quote' => ['idSuffix' => '"onclick="x'],
            'selector characters' => ['idSuffix' => '],#x'],
            'space' => ['idSuffix' => 'a b'],
            'trailing newline' => ['idSuffix' => "_x\n"],
        ];
    }

    #[DataProvider('provideIdSuffix')]
    public function testGetPlaceHolder(string $idSuffix, string $expectedId): void
    {
        $html = (string) $this->createSearchForm()->getPlaceHolder(false, $idSuffix);
        $this->assertStringContainsString('id="' . $expectedId . '"', $html);
    }

    #[DataProvider('provideIdSuffixInvalid')]
    public function testGetPlaceHolderInvalidIdSuffix(string $idSuffix): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->createSearchForm()->getPlaceHolder(false, $idSuffix);
    }

    #[DataProvider('provideIdSuffix')]
    public function testGetFilterButton(string $idSuffix, string $expectedId): void
    {
        $html = (string) $this->createSearchForm()->getFilterButton(false, $idSuffix);
        $this->assertStringContainsString('aria-controls="' . $expectedId . '"', $html);
    }

    #[DataProvider('provideIdSuffixInvalid')]
    public function testGetFilterButtonInvalidIdSuffix(string $idSuffix): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->createSearchForm()->getFilterButton(false, $idSuffix);
    }
}
