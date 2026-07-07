<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Server;

use haddowg\JsonApiBundle\Server\RouteDescriptorRegistry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Characterizes the {@see RouteDescriptorRegistry}: the per-server, per-type route
 * descriptor map (uriType / operations / tags / shape) surfaced so the OpenAPI
 * metadata source can enumerate a server's types.
 */
#[Group('spec:openapi')]
final class RouteDescriptorRegistryTest extends TestCase
{
    #[Test]
    public function itReturnsAServerSDescriptorsKeyedByType(): void
    {
        $registry = new RouteDescriptorRegistry([
            'default' => [
                'articles' => $this->descriptor('articles', ['Content']),
                'people' => $this->descriptor('people', []),
            ],
            'admin' => [
                'widgets' => $this->descriptor('widgets', []),
            ],
        ]);

        self::assertSame(['articles', 'people'], \array_keys($registry->forServer('default')));
        self::assertSame(['widgets'], \array_keys($registry->forServer('admin')));
    }

    #[Test]
    public function anUnknownServerYieldsAnEmptyMap(): void
    {
        self::assertSame([], (new RouteDescriptorRegistry())->forServer('missing'));
    }

    #[Test]
    public function itResolvesASingleTypeDescriptor(): void
    {
        $registry = new RouteDescriptorRegistry([
            'default' => ['articles' => $this->descriptor('articles', ['Content'])],
        ]);

        self::assertSame(['Content'], $registry->forType('default', 'articles')['tags'] ?? null);
        self::assertNull($registry->forType('default', 'missing'));
    }

    #[Test]
    public function itListsTheServerNamesForCombinedAssembly(): void
    {
        $registry = new RouteDescriptorRegistry([
            'default' => ['articles' => $this->descriptor('articles', [])],
            'admin' => ['widgets' => $this->descriptor('widgets', [])],
        ]);

        self::assertSame(['default', 'admin'], $registry->serverNames());
        self::assertSame([], (new RouteDescriptorRegistry())->serverNames());
    }

    /**
     * @param list<string> $tags
     *
     * @return array{uriType: string, isResource: bool, hasHydrator: bool, hasRelations: bool, operations: list<string>, tags: list<string>, responses: array<string, list<array{status: int, jobType?: string}>>}
     */
    private function descriptor(string $type, array $tags): array
    {
        return [
            'uriType' => $type,
            'isResource' => true,
            'hasHydrator' => true,
            'hasRelations' => true,
            'operations' => ['FetchCollection', 'FetchOne'],
            'tags' => $tags,
            'responses' => [],
        ];
    }
}
