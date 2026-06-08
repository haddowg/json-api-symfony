<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Validation;

use haddowg\JsonApiBundle\Validation\JsonPointerBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JsonPointerBuilderTest extends TestCase
{
    #[Test]
    #[DataProvider('paths')]
    public function itBuildsAnAttributePointerFromASymfonyPropertyPath(string $propertyPath, string $expected): void
    {
        self::assertSame($expected, (new JsonPointerBuilder())->forAttribute($propertyPath));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function paths(): iterable
    {
        yield 'top-level attribute' => ['[title]', '/data/attributes/title'];
        yield 'nested attribute' => ['[address][city]', '/data/attributes/address/city'];
        yield 'document-level (empty path)' => ['', '/data/attributes'];
        yield 'segment with a slash is escaped' => ['[a/b]', '/data/attributes/a~1b'];
        yield 'segment with a tilde is escaped' => ['[a~b]', '/data/attributes/a~0b'];
    }
}
