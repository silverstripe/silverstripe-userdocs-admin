<?php

namespace SilverStripe\UserDocs\Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserDocs\Admin\UserDocsClassResolver;
use SilverStripe\UserDocs\Admin\UserDocsManifest;
use SilverStripe\UserDocs\Tests\Admin\UserDocsClassResolverTest\StubUserDocsManifest;
use SilverStripe\UserDocs\Tests\DocClasses\BaseRecord;
use SilverStripe\UserDocs\Tests\DocClasses\ChildRecord;
use SilverStripe\UserDocs\Tests\DocClasses\ParentRecord;
use SilverStripe\UserDocs\Tests\DocClasses\UnmappedRecord;

class UserDocsClassResolverTest extends SapphireTest
{
    public static function provideGetSlugsForClass(): array
    {
        return [
            'class with docs' => [
                'class' => ChildRecord::class,
                'expected' => ['child/primary', 'child/other'],
            ],
            'class name with a leading backslash and a different case' => [
                'class' => '\\' . strtoupper(ChildRecord::class),
                'expected' => ['child/primary', 'child/other'],
            ],
            'docs for parent classes are not used' => [
                'class' => UnmappedRecord::class,
                'expected' => [],
            ],
        ];
    }

    #[DataProvider('provideGetSlugsForClass')]
    public function testGetSlugsForClass(string $class, array $expected): void
    {
        $this->useClassMap([
            ChildRecord::class => ['child/primary', 'child/other'],
            ParentRecord::class => ['parent'],
        ]);

        $this->assertSame($expected, UserDocsClassResolver::singleton()->getSlugsForClass($class));
    }

    public static function provideGetSlugForParentClass(): array
    {
        return [
            'the class itself is not used' => [
                'class' => ChildRecord::class,
                'expected' => 'base',
            ],
            'nearest parent class with docs' => [
                'class' => UnmappedRecord::class,
                'expected' => 'child/primary',
            ],
            'class with no parent class' => [
                'class' => BaseRecord::class,
                'expected' => null,
            ],
        ];
    }

    #[DataProvider('provideGetSlugForParentClass')]
    public function testGetSlugForParentClass(string $class, ?string $expected): void
    {
        $this->useClassMap([
            ChildRecord::class => ['child/primary', 'child/other'],
            BaseRecord::class => ['base'],
        ]);

        $this->assertSame($expected, UserDocsClassResolver::singleton()->getSlugForParentClass($class));
    }

    /**
     * Use a manifest with the given slugs for each class.
     *
     * @param array $classMap Lists of slugs keyed by class name
     */
    private function useClassMap(array $classMap): void
    {
        Injector::inst()->registerService(
            new StubUserDocsManifest(array_change_key_case($classMap, CASE_LOWER)),
            UserDocsManifest::class
        );
    }
}
