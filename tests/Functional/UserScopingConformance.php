<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use PHPUnit\Framework\Attributes\Test;

/**
 * The custom scoped provider example from the authorization guide, shared by both
 * kernels: `memos` are served only to their owner. Folder or widget 1 (`$parent`) holds
 * memos 1 (ada), 2 (grace) and 3 (ada).
 *
 * @phpstan-require-extends JsonApiFunctionalTestCase
 */
trait UserScopingConformance
{
    #[Test]
    public function the_scoped_provider_limits_the_collection_and_its_total(): void
    {
        $document = $this->scopedDocument('ada', '/memos?page[number]=1&page[size]=10&withCount=_self_');
        self::assertSame(['1', '3'], $this->scopedIds($document['data'] ?? null));
        self::assertSame(2, $this->scopedValue($document, 'meta', 'page', 'total'));

        self::assertSame(404, $this->handle('/memos/2', 'GET', null, ['HTTP_AUTHORIZATION' => 'Bearer ada'])->getStatusCode());
    }

    #[Test]
    public function the_scoped_provider_limits_every_relationship_that_reaches_the_type(): void
    {
        $this->assertScopedRelation('ada', $this->memoParent(), 'memos', ['1', '3']);
        $this->assertScopedRelation('grace', $this->memoParent(), 'memos', ['2']);
    }

    #[Test]
    public function the_scoped_provider_keeps_pages_full_and_totals_exact(): void
    {
        $first = $this->scopedDocument('ada', $this->memoParent() . '/memos?withCount=_self_&page[number]=1&page[size]=1');
        $second = $this->scopedDocument('ada', $this->memoParent() . '/memos?withCount=_self_&page[number]=2&page[size]=1');

        self::assertSame(['1'], $this->scopedIds($first['data'] ?? null));
        self::assertSame(['3'], $this->scopedIds($second['data'] ?? null));
        self::assertSame(2, $this->scopedValue($first, 'meta', 'total'));
    }

    /**
     * The path of the resource holding the memos.
     */
    protected function memoParent(): string
    {
        return '/ownedFolders/1';
    }

    /**
     * Asserts `$relation` on `$parent` shows exactly `$expected` to `$user` everywhere it
     * renders: linkage, included, `?withCount`, the related endpoint's page and total,
     * and the relationship endpoint.
     *
     * @param list<string> $expected
     */
    private function assertScopedRelation(string $user, string $parent, string $relation, array $expected): void
    {
        $document = $this->scopedDocument($user, $parent . '?include=' . $relation . '&withCount=' . $relation);
        $relationship = $this->scopedRelationship($document, $relation);
        self::assertSame($expected, $this->scopedIds($relationship['data'] ?? null), $user);
        self::assertSame(\count($expected), $this->scopedValue($relationship, 'meta', 'total'), $user);
        self::assertSame($expected, $this->scopedIds($document['included'] ?? []), $user);

        $related = $this->scopedDocument($user, $parent . '/' . $relation . '?withCount=_self_');
        self::assertSame($expected, $this->scopedIds($related['data'] ?? null), $user);
        self::assertSame(\count($expected), $this->scopedValue($related, 'meta', 'total'), $user);

        self::assertSame($expected, $this->scopedIds($this->scopedDocument($user, $parent . '/relationships/' . $relation)['data'] ?? null), $user);
    }

    /**
     * @param array<string, string> $server
     *
     * @return array<string, mixed>
     */
    private function scopedDocument(string $user, string $path, array $server = []): array
    {
        $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $user;
        $server['HTTP_ACCEPT'] ??= 'application/vnd.api+json;profile="' . CountableProfile::URI . '"';
        $response = $this->handle($path, 'GET', null, $server);
        self::assertSame(200, $response->getStatusCode(), $path . ': ' . $response->getContent());

        return $this->decode($response);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function scopedRelationship(array $document, string $name): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);
        $relationship = $relationships[$name] ?? null;
        self::assertIsArray($relationship);

        /** @var array<string, mixed> $relationship */
        return $relationship;
    }

    /**
     * The ids of a list of resources or identifiers, sorted.
     *
     * @return list<string>
     */
    private function scopedIds(mixed $resources): array
    {
        self::assertIsArray($resources);
        $ids = [];
        foreach ($resources as $resource) {
            self::assertIsArray($resource);
            $ids[] = \is_string($resource['id'] ?? null) ? $resource['id'] : '';
        }
        \sort($ids);

        return $ids;
    }

    /**
     * @param array<mixed> $array
     */
    private function scopedValue(array $array, string ...$keys): mixed
    {
        $value = $array;
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
