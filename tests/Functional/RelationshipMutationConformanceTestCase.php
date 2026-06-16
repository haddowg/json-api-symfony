<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Phase-3 S3 + S4 acceptance suite: the relationship-mutation endpoints
 * (`PATCH`/`POST`/`DELETE /{type}/{id}/relationships/{relationship}`) **and** the
 * whole-resource writes that embed relationships in `data.relationships`, on both
 * providers, each mutation re-fetched to prove the change persisted through the
 * {@see \haddowg\JsonApiBundle\DataPersister\DataPersisterInterface::mutateRelationship()}
 * seam.
 *
 *  - `PATCH …/relationships/author {data:{…}}` — replace the to-one; `{data:null}`
 *    clears it.
 *  - `PATCH …/relationships/comments {data:[…]}` — full to-many replacement.
 *  - `POST …/relationships/comments {data:[…]}` — add members (idempotent).
 *  - `DELETE …/relationships/comments {data:[…]}` — remove members.
 *  - mutability guards: a `cannotReplace` relation `PATCH` → `403`; a `cannotRemove`
 *    relation `DELETE` → `403`.
 *  - cardinality: `POST`/`DELETE` on a to-one → `400`.
 *  - unknown relationship → `404`; missing parent → `404`.
 *  - S4: `POST /articles {…, relationships:{author, comments}}` — a whole-resource
 *    create sets both associations through the persister seam (`201` + Location);
 *    `PATCH /articles/{id}` replacing `author`/`comments` via `data.relationships`
 *    (`200`); a create/update with no relationships is unchanged, and a partial
 *    PATCH leaves the unmentioned associations untouched.
 *
 * Abstract over the kernel so the same assertions run against the in-memory
 * ({@see InMemoryRelationshipMutationTest}) and Doctrine
 * ({@see DoctrineRelationshipMutationTest}) providers — a failure on one localizes
 * the bug to that persister's relationship-mutation execution.
 */
abstract class RelationshipMutationConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    #[Group('spec:updating-relationships')]
    public function patchingAToOneReplacesItAndPersists(): void
    {
        // Article 1 is authored by author 1; replace with author 2.
        $response = $this->handle('/articles/1/relationships/author', 'PATCH', [
            'data' => ['type' => 'authors', 'id' => '2'],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['type' => 'authors', 'id' => '2'], $this->linkage($response));

        // The replacement persisted: a fresh linkage read reflects it.
        self::assertSame(
            ['type' => 'authors', 'id' => '2'],
            $this->linkageOf('/articles/1/relationships/author'),
        );
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function patchingAToOneWithNullDataClearsItAndPersists(): void
    {
        $response = $this->handle('/articles/1/relationships/author', 'PATCH', ['data' => null]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertNull($this->linkage($response));

        $document = $this->fetchDocument('/articles/1/relationships/author');
        self::assertArrayHasKey('data', $document);
        self::assertNull($document['data']);
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function patchingAToManyReplacesTheWholeSetAndPersists(): void
    {
        // Article 1 owns comments 1, 2; replace its comment set with [comment 4].
        $response = $this->handle('/articles/1/relationships/comments', 'PATCH', [
            'data' => [['type' => 'comments', 'id' => '4']],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([['type' => 'comments', 'id' => '4']], $this->identifiers($response));

        self::assertSame(
            [['type' => 'comments', 'id' => '4']],
            $this->identifiersOf('/articles/1/relationships/comments'),
        );
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function postingToAToManyAddsMembersIdempotentlyAndPersists(): void
    {
        // Article 1 owns comments 1, 2. Add comments 4 and 1 (already present): the result is
        // comments 1, 2, 4 — comment 1 not duplicated (set semantics).
        $response = $this->handle('/articles/1/relationships/comments', 'POST', [
            'data' => [
                ['type' => 'comments', 'id' => '4'],
                ['type' => 'comments', 'id' => '1'],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['1', '2', '4'], $this->commentIds($this->identifiers($response)));

        self::assertSame(
            ['1', '2', '4'],
            $this->commentIds($this->identifiersOf('/articles/1/relationships/comments')),
        );
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function deletingFromAToManyRemovesMembersAndPersists(): void
    {
        // Article 1 owns comments 1, 2. Remove comment 1: the result is comment 2.
        $response = $this->handle('/articles/1/relationships/comments', 'DELETE', [
            'data' => [['type' => 'comments', 'id' => '1']],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['2'], $this->commentIds($this->identifiers($response)));

        self::assertSame(
            ['2'],
            $this->commentIds($this->identifiersOf('/articles/1/relationships/comments')),
        );
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function creatingAResourceWithRelationshipsSetsThemThroughThePersisterSeam(): void
    {
        // POST a whole article carrying both a to-one `author` and a to-many
        // `comments` in `data.relationships`: the handler hydrates the attributes
        // via core, then sets the associations through the persister seam (so a
        // typed entity never gets a scalar id on an association property).
        $response = $this->handle('/articles', 'POST', [
            'data' => [
                'type' => 'articles',
                'attributes' => [
                    'title' => 'A related article',
                    'body' => 'With an author and comments.',
                    'category' => 'news',
                ],
                'relationships' => [
                    'author' => ['data' => ['type' => 'authors', 'id' => '1']],
                    'comments' => ['data' => [
                        ['type' => 'comments', 'id' => '1'],
                        ['type' => 'comments', 'id' => '2'],
                    ]],
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $id = $data['id'] ?? null;
        self::assertIsString($id);
        self::assertNotSame('', $id);
        self::assertSame('https://example.test/articles/' . $id, $response->headers->get('Location'));

        // The attributes were hydrated by core as usual.
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('A related article', $attributes['title'] ?? null);

        // The associations persisted: a fresh linkage read (re-fetched through the
        // read provider — the Doctrine subclass clears the identity map first, so
        // the foreign keys were actually written) reflects the author + comments.
        self::assertSame(
            ['type' => 'authors', 'id' => '1'],
            $this->linkageOf('/articles/' . $id . '/relationships/author'),
        );
        self::assertSame(
            ['1', '2'],
            $this->commentIds($this->identifiersOf('/articles/' . $id . '/relationships/comments')),
        );
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function aReadOnlyRelationshipIsIgnoredOnAWholeResourceCreate(): void
    {
        // `readOnlyAuthor` reuses the `author` association but is declared readOnly(),
        // so naming it in a write body is silently skipped — a server-assigned
        // association can't be set through the body. The created article therefore has
        // no author (a writable relation would have set it).
        $response = $this->handle('/articles', 'POST', [
            'data' => [
                'type' => 'articles',
                'attributes' => ['title' => 'No author via read-only', 'body' => 'x', 'category' => 'news'],
                'relationships' => [
                    'readOnlyAuthor' => ['data' => ['type' => 'authors', 'id' => '1']],
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $id = $data['id'] ?? null;
        self::assertIsString($id);

        self::assertNull($this->linkageOf('/articles/' . $id . '/relationships/author'));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function aReadOnlyRelationshipIsNotOverwrittenByAWholeResourceUpdate(): void
    {
        // Article 1 is seeded with author 1; a PATCH naming readOnlyAuthor=2 must be
        // skipped, leaving the author unchanged — the over-posting guard.
        $response = $this->handle('/articles/1', 'PATCH', [
            'data' => [
                'type' => 'articles',
                'id' => '1',
                'relationships' => [
                    'readOnlyAuthor' => ['data' => ['type' => 'authors', 'id' => '2']],
                ],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(
            ['type' => 'authors', 'id' => '1'],
            $this->linkageOf('/articles/1/relationships/author'),
        );
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function creatingAResourceWithoutRelationshipsStillWorks(): void
    {
        // No `data.relationships`: the attributes-only create path is unchanged.
        $response = $this->handle('/articles', 'POST', [
            'data' => [
                'type' => 'articles',
                'attributes' => [
                    'title' => 'A standalone article',
                    'body' => 'No relationships.',
                    'category' => 'opinion',
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $id = $data['id'] ?? null;
        self::assertIsString($id);

        // It has no author and no comments.
        self::assertNull($this->linkageOf('/articles/' . $id . '/relationships/author'));
        self::assertSame([], $this->identifiersOf('/articles/' . $id . '/relationships/comments'));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function patchingAResourceReplacesItsRelationshipsThroughThePersisterSeam(): void
    {
        // Article 1 is authored by author 1 and owns comments 1, 2. A whole-resource PATCH
        // carrying `data.relationships` replaces the author with author 2 and the comment
        // set with [comment 4] — applied through the same persister seam as the
        // relationship endpoints, not core's scalar-id hydration.
        $response = $this->handle('/articles/1', 'PATCH', [
            'data' => [
                'type' => 'articles',
                'id' => '1',
                'attributes' => ['title' => 'A retitled, re-linked article'],
                'relationships' => [
                    'author' => ['data' => ['type' => 'authors', 'id' => '2']],
                    'comments' => ['data' => [['type' => 'comments', 'id' => '4']]],
                ],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('A retitled, re-linked article', $attributes['title'] ?? null);

        // The replacements persisted (re-fetched through the read provider).
        self::assertSame(
            ['type' => 'authors', 'id' => '2'],
            $this->linkageOf('/articles/1/relationships/author'),
        );
        self::assertSame(
            ['4'],
            $this->commentIds($this->identifiersOf('/articles/1/relationships/comments')),
        );
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function patchingAResourceWithoutRelationshipsLeavesThemUntouched(): void
    {
        // A PATCH that supplies no `data.relationships` must not disturb the
        // existing associations: article 1 keeps author 1 and comments 1, 2.
        $response = $this->handle('/articles/1', 'PATCH', [
            'data' => [
                'type' => 'articles',
                'id' => '1',
                'attributes' => ['title' => 'Only the title changes'],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(
            ['type' => 'authors', 'id' => '1'],
            $this->linkageOf('/articles/1/relationships/author'),
        );
        self::assertSame(
            ['1', '2'],
            $this->commentIds($this->identifiersOf('/articles/1/relationships/comments')),
        );
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function patchingAResourceReplacingOnlyTheToOneLeavesTheToManyUntouched(): void
    {
        // A whole-resource PATCH naming only `author` replaces just that
        // association; the unmentioned `comments` set is left as-is.
        $response = $this->handle('/articles/1', 'PATCH', [
            'data' => [
                'type' => 'articles',
                'id' => '1',
                'relationships' => [
                    'author' => ['data' => ['type' => 'authors', 'id' => '2']],
                ],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(
            ['type' => 'authors', 'id' => '2'],
            $this->linkageOf('/articles/1/relationships/author'),
        );
        self::assertSame(
            ['1', '2'],
            $this->commentIds($this->identifiersOf('/articles/1/relationships/comments')),
        );
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function patchingACannotReplaceRelationIsForbidden(): void
    {
        // lockedAuthor reads the `author` property but forbids replacement.
        $response = $this->handle('/articles/1/relationships/lockedAuthor', 'PATCH', [
            'data' => ['type' => 'authors', 'id' => '2'],
        ]);

        $this->assertError($response, 403);

        // The forbidden mutation did not persist: article 1 still has author 1.
        self::assertSame(
            ['type' => 'authors', 'id' => '1'],
            $this->linkageOf('/articles/1/relationships/author'),
        );
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function deletingFromACannotRemoveRelationIsForbidden(): void
    {
        // lockedComments reads the `comments` property but forbids removal.
        $response = $this->handle('/articles/1/relationships/lockedComments', 'DELETE', [
            'data' => [['type' => 'comments', 'id' => '1']],
        ]);

        $this->assertError($response, 403);

        // The forbidden removal did not persist: article 1 still owns comments 1, 2.
        self::assertSame(
            ['1', '2'],
            $this->commentIds($this->identifiersOf('/articles/1/relationships/comments')),
        );
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function postingToAToOneIsACardinalityError(): void
    {
        $response = $this->handle('/articles/1/relationships/author', 'POST', [
            'data' => [['type' => 'authors', 'id' => '2']],
        ]);

        $this->assertError($response, 400);
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function deletingFromAToOneIsACardinalityError(): void
    {
        $response = $this->handle('/articles/1/relationships/author', 'DELETE', [
            'data' => [['type' => 'authors', 'id' => '2']],
        ]);

        $this->assertError($response, 400);
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function mutatingAnUnknownRelationshipIs404(): void
    {
        $response = $this->handle('/articles/1/relationships/bogusrel', 'PATCH', [
            'data' => ['type' => 'authors', 'id' => '2'],
        ]);

        $this->assertError($response, 404);
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function mutatingARelationshipOnAMissingParentIs404(): void
    {
        $response = $this->handle('/articles/999/relationships/author', 'PATCH', [
            'data' => ['type' => 'authors', 'id' => '2'],
        ]);

        $this->assertError($response, 404);
    }

    // --- helpers ---------------------------------------------------------------

    /**
     * The `data` linkage of a relationship-endpoint response (a single identifier,
     * a list, or null).
     */
    private function linkage(Response $response): mixed
    {
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->decode($response)['data'] ?? null;
    }

    /**
     * The `data` linkage at a relationship-endpoint path (asserts a 200).
     */
    private function linkageOf(string $path): mixed
    {
        return $this->fetchDocument($path)['data'] ?? null;
    }

    /**
     * Detaches any persisted state before a re-fetch so the read is a genuine
     * round-trip to the store, not a managed in-memory instance. The Doctrine
     * provider overrides this to clear the identity map (otherwise a mutated
     * entity would be served from the unit of work and the re-fetch would not
     * prove the change actually reached the database); the in-memory provider has
     * no identity map, so the default is a no-op.
     */
    protected function detachPersistedState(): void {}

    /**
     * @return array<string, mixed>
     */
    private function fetchDocument(string $path): array
    {
        $this->detachPersistedState();
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * The to-many `data` of a relationship-endpoint response reduced to a list of
     * `{type, id}` identifiers.
     *
     * @return list<array{type: mixed, id: mixed}>
     */
    private function identifiers(Response $response): array
    {
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->reduceIdentifiers($this->decode($response)['data'] ?? null);
    }

    /**
     * The to-many identifiers at a relationship-endpoint path (asserts a 200).
     *
     * @return list<array{type: mixed, id: mixed}>
     */
    private function identifiersOf(string $path): array
    {
        return $this->reduceIdentifiers($this->fetchDocument($path)['data'] ?? null);
    }

    /**
     * @return list<array{type: mixed, id: mixed}>
     */
    private function reduceIdentifiers(mixed $data): array
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
     * The sorted comment ids of a to-many identifier list — order-independent so a
     * collection's storage order does not couple the assertion to a provider.
     *
     * @param list<array{type: mixed, id: mixed}> $identifiers
     *
     * @return list<string>
     */
    private function commentIds(array $identifiers): array
    {
        $ids = [];
        foreach ($identifiers as $identifier) {
            self::assertSame('comments', $identifier['type']);
            $id = $identifier['id'];
            self::assertIsString($id);
            $ids[] = $id;
        }

        \sort($ids);

        return $ids;
    }

    /**
     * Asserts a JSON:API error document at the given status.
     */
    private function assertError(Response $response, int $status): void
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $firstError = $errors[0] ?? null;
        self::assertIsArray($firstError);
        self::assertSame((string) $status, $firstError['status'] ?? null);
    }
}
