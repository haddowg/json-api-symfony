<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\OpenApi\Metadata;

use haddowg\JsonApiBundle\OpenApi\Metadata\TagNameResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Characterizes the {@see TagNameResolver} (design §4.7, D15): the default OpenAPI
 * tag name for a type is its humanized, title-cased, pluralized form — a heuristic,
 * always overridable by an explicit `tags` ref.
 */
#[Group('spec:openapi')]
final class TagNameResolverTest extends TestCase
{
    #[Test]
    #[DataProvider('typeToTag')]
    public function itHumanizesTitleCasesAndPluralizesAType(string $type, string $expected): void
    {
        self::assertSame($expected, (new TagNameResolver())->defaultFor($type));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function typeToTag(): iterable
    {
        yield 'hyphenated compound' => ['blog-post', 'Blog Posts'];
        yield 'single word' => ['genre', 'Genres'];
        yield 'snake compound' => ['music_album', 'Music Albums'];
        yield 'camelCase compound' => ['blogPost', 'Blog Posts'];
        yield 'y to ies' => ['category', 'Categories'];
    }
}
