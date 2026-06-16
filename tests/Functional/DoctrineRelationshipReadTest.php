<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see RelationshipReadConformanceTestCase} against the Doctrine provider: the
 * same relationship-linkage assertions as the in-memory suite, executed as real
 * DQL over an in-memory SQLite database created per test and seeded — with the
 * `ManyToOne` author and `OneToMany` comment associations wired — through the
 * Foundry factories from the shared {@see \haddowg\JsonApiBundle\Tests\Functional\App\ArticleFixtures}.
 */
final class DoctrineRelationshipReadTest extends RelationshipReadConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aLoadAwareToManyOverAnUninitialisedCollectionOmitsDataButKeepsLinks(): void
    {
        // Article 1 features comments c1, c2 in the `featuredComments` association
        // backing the load-aware `lazyComments` relation. No eager relation reads
        // that association, so after a fresh find() it is an uninitialised
        // PersistentCollection: the Doctrine load-state predicate reports it
        // not-loaded, so `data` is OMITTED (no lazy load triggered) while the
        // convention links are still emitted.
        $relationships = $this->relationshipsOf('/articles/1');

        $lazyComments = $relationships['lazyComments'] ?? null;
        self::assertIsArray($lazyComments);
        self::assertArrayNotHasKey('data', $lazyComments);
        self::assertSame(
            [
                'self' => 'https://example.test/articles/1/relationships/lazyComments',
                'related' => 'https://example.test/articles/1/lazyComments',
            ],
            $lazyComments['links'] ?? null,
        );
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function includingALoadAwareToManyEmitsItsDataAnyway(): void
    {
        // Include-wins: ?include=lazyComments forces the relationship to be
        // resolved, so the deferred linkage read fires and `data` is present even
        // though the load-state policy would otherwise omit it over the
        // uninitialised collection.
        $relationships = $this->relationshipsOf('/articles/1?include=lazyComments');

        $lazyComments = $relationships['lazyComments'] ?? null;
        self::assertIsArray($lazyComments);
        self::assertArrayHasKey('data', $lazyComments);
        self::assertSame(
            [
                ['type' => 'comments', 'id' => '1'],
                ['type' => 'comments', 'id' => '2'],
            ],
            $this->identifiers($lazyComments['data']),
        );
    }

    /**
     * The `relationships` member of a single-resource fetch's primary data.
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

    /**
     * Reduces a to-many `data` payload to a list of `{type, id}` identifiers.
     *
     * @return list<array{type: mixed, id: mixed}>
     */
    private function identifiers(mixed $data): array
    {
        self::assertIsArray($data);

        $identifiers = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            $identifiers[] = ['type' => $identifier['type'] ?? null, 'id' => $identifier['id'] ?? null];
        }

        return $identifiers;
    }
}
