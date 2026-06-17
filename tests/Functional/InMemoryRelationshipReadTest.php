<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\JsonApiTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see RelationshipReadConformanceTestCase} against the in-memory provider —
 * the conformance witness half of the dual-provider relationship-read contract.
 *
 * The in-memory `articles` resource also declares an extra `editor` relation
 * that opts out of the convention links via
 * {@see \haddowg\JsonApi\Resource\Field\AbstractRelation::withoutLinks()}, so
 * this subclass additionally witnesses the opt-out: data still renders, no
 * `links` member appears.
 */
final class InMemoryRelationshipReadTest extends RelationshipReadConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return JsonApiTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelationshipDeclaringWithoutLinksOmitsLinksButStillRendersData(): void
    {
        $response = $this->handle('/articles/1');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);

        // `editor` is backed by the same `author` property, so its data renders
        // the author identifier — but it declared ->withoutLinks(), so no links.
        $editor = $relationships['editor'] ?? null;
        self::assertIsArray($editor);
        self::assertSame(['type' => 'authors', 'id' => '1'], $editor['data'] ?? null);
        self::assertArrayNotHasKey('links', $editor);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aToManyRelationshipUnderTheLoadStatePolicyStillEmitsDataInMemory(): void
    {
        // `lazyComments` is a lazy-by-default to-many (core ADR 0067), but the
        // in-memory kernel wires no load-state predicate (no doctrine/orm), so core
        // treats every relation as loaded: the in-memory `comments` are materialised
        // objects, so the `data` member is present exactly as for the eager
        // `comments` relation. The lazy policy is inert without a storage adapter.
        $relationships = $this->relationshipsOf('/articles/1');

        $lazyComments = $relationships['lazyComments'] ?? null;
        self::assertIsArray($lazyComments);
        self::assertArrayHasKey('data', $lazyComments);
        self::assertSame(
            [
                ['type' => 'comments', 'id' => '1'],
                ['type' => 'comments', 'id' => '2'],
            ],
            $this->normaliseIdentifiers($lazyComments['data']),
        );
    }

    /**
     * Reduces a to-many `data` payload to a list of `{type, id}` identifiers.
     *
     * @return list<array{type: mixed, id: mixed}>
     */
    private function normaliseIdentifiers(mixed $data): array
    {
        self::assertIsArray($data);

        $identifiers = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            $identifiers[] = ['type' => $identifier['type'] ?? null, 'id' => $identifier['id'] ?? null];
        }

        return $identifiers;
    }

    /**
     * The `relationships` member of a resource object on a single-resource fetch.
     *
     * @return array<string, mixed>
     */
    private function relationshipsOf(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);

        /** @var array<string, mixed> $relationships */
        return $relationships;
    }
}
