<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The dual-provider acceptance suite for **one entity / domain object backing TWO
 * JSON:API resource types**, run identically against the in-memory provider
 * ({@see InMemoryMultiTypeEntityTest}) and the Doctrine provider
 * ({@see DoctrineMultiTypeEntityTest}).
 *
 * One Member record is exposed as the full `members` type (display name, email,
 * secret note) and the curated `public-members` type (display name only). Both
 * resources name the same backing — in the Doctrine kernel both declare
 * `#[AsJsonApiResource(entity: MemberEntity::class)]`, which the bundle's type→entity
 * map accepts (it rejects only one type → two entities); in the in-memory kernel two
 * providers read the SAME Member objects. A type is always supplied by context (the
 * route for primary data, the relation's declared `make()` type for linkage), so the
 * same record renders under either type.
 *
 * The `posts` resource's to-one `author` relation declares its target as the curated
 * `public-members` type, so a post's author renders that type's linkage and includes
 * the curated view — even though the same Member is also a `members`. `posts` is
 * writable, so a relationship mutation sending the curated type resolves.
 *
 * The cases prove, end-to-end and genuinely (independent reads, the actual `type`
 * member) on both providers:
 *  1. `GET /members/{id}` renders the FULL view;
 *  2. `GET /public-members/{id}` renders the SAME record as the CURATED view (fewer
 *     fields), confirmed against the full view's id + display name;
 *  3. a relationship targeting the curated type renders `{type: public-members, id}`;
 *  4. `?include=author` includes the curated-type resource (display name only);
 *  5. a relationship mutation sending `{type: public-members, id}` resolves correctly;
 *  6. a relationship mutation sending the WRONG resource type (`{type: members}` where
 *     the relation declares `public-members`) is rejected with a `409` resource-type
 *     conflict — see {@see aRelationshipMutationWithAWrongTypeIsRejected()}.
 *
 * The validator enforces each relation's declared related types
 * ({@see \haddowg\JsonApi\Resource\Field\RelationInterface::relatedTypes()}): a linkage
 * whose `type` is not among them is the linkage twin of core's create-path
 * `ResourceTypeUnacceptable` (a `409`, code `RESOURCE_TYPE_UNACCEPTABLE`). This is a
 * GENERAL guard in {@see \haddowg\JsonApiBundle\Validation\ResourceValidator},
 * independent of the multi-type capability — it gates every monomorphic relation
 * equally — but the multi-type fixtures make a wrong-but-real type (`members` vs the
 * declared `public-members`, both backing the same record) the sharpest witness. The
 * capability itself — two types over one record, and the relation rendering the
 * declared type — works on both providers.
 */
abstract class MultiTypeEntityConformanceTestCase extends JsonApiFunctionalTestCase
{
    // --- (1) the full view ---------------------------------------------------

    #[Test]
    #[Group('spec:fetching')]
    public function theFullTypeRendersEveryField(): void
    {
        $data = $this->fetchResource('/members/1');

        self::assertSame('members', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);

        $attributes = $this->attributesOf($data);
        self::assertSame('Ada', $attributes['displayName'] ?? null);
        // The full view carries the private fields.
        self::assertSame('ada@example.test', $attributes['email'] ?? null);
        self::assertArrayHasKey('secretNote', $attributes);
    }

    // --- (2) the curated view of the SAME record -----------------------------

    #[Test]
    #[Group('spec:fetching')]
    public function theCuratedTypeRendersTheSameRecordWithFewerFields(): void
    {
        // Independently read the SAME record (id 1) through the curated type.
        $full = $this->fetchResource('/members/1');
        $curated = $this->fetchResource('/public-members/1');

        // Same backing record — same id and display name as the full view.
        self::assertSame('public-members', $curated['type'] ?? null);
        self::assertSame($full['id'] ?? null, $curated['id'] ?? null);

        $fullAttributes = $this->attributesOf($full);
        $curatedAttributes = $this->attributesOf($curated);
        self::assertSame($fullAttributes['displayName'] ?? null, $curatedAttributes['displayName'] ?? null);
        self::assertSame('Ada', $curatedAttributes['displayName'] ?? null);

        // The curated view is strictly narrower: the private fields are ABSENT (the
        // curation is the field inventory, not a runtime filter — no sparse fieldset
        // can resurface them).
        self::assertArrayNotHasKey('email', $curatedAttributes);
        self::assertArrayNotHasKey('secretNote', $curatedAttributes);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aSparseFieldsetCannotResurfaceACuratedAwayField(): void
    {
        // `fields[public-members]=email` names a field the curated view does not
        // declare; with strict query parameters on (the default) this is a 400, and
        // either way `email` never appears — the private field is unreachable through
        // the curated type.
        $response = $this->handle('/public-members/1?fields[public-members]=email');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
    }

    // --- (3) linkage targeting the curated type ------------------------------

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelationshipTargetingTheCuratedTypeRendersThatTypesLinkage(): void
    {
        $relationships = $this->relationshipsOf($this->fetchResource('/posts/1'));

        $author = $relationships['author'] ?? null;
        self::assertIsArray($author);
        // The relation declares the make() type 'public-members', so the linkage identifies the
        // curated type for the SAME Member that is also a `members` record.
        self::assertSame(['type' => 'public-members', 'id' => '1'], $author['data'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theRelationshipEndpointRendersTheCuratedTypeLinkage(): void
    {
        $document = $this->fetchDocument('/posts/1/relationships/author');

        self::assertSame(['type' => 'public-members', 'id' => '1'], $document['data'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theRelatedEndpointRendersTheCuratedResource(): void
    {
        $data = $this->fetchResource('/posts/1/author');

        self::assertSame('public-members', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);

        $attributes = $this->attributesOf($data);
        self::assertSame('Ada', $attributes['displayName'] ?? null);
        self::assertArrayNotHasKey('email', $attributes);
    }

    // --- (4) include expands the curated type --------------------------------

    #[Test]
    #[Group('spec:fetching-includes')]
    public function includingTheRelationExpandsTheCuratedResource(): void
    {
        $document = $this->fetchDocument('/posts/1?include=author');

        $included = $document['included'] ?? null;
        self::assertIsArray($included);

        $profile = $this->findIncluded($included, 'public-members', '1');
        self::assertNotNull($profile, 'the curated public-members resource is included');

        $attributes = $this->attributesOf($profile);
        self::assertSame('Ada', $attributes['displayName'] ?? null);
        // The included resource is the curated view — its private fields are absent.
        self::assertArrayNotHasKey('email', $attributes);
        self::assertArrayNotHasKey('secretNote', $attributes);
    }

    // --- (5) mutation sending the curated type resolves ----------------------

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelationshipMutationSendingTheCuratedTypeResolves(): void
    {
        // Post 2 has no author. PATCH its author relationship to member 2, sending the
        // curated `public-members` type — the persister resolves it back to the stored
        // Member and sets the association.
        $response = $this->handle('/posts/2/relationships/author', 'PATCH', [
            'data' => ['type' => 'public-members', 'id' => '2'],
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        // An independent follow-up read confirms the association was set — linkage is
        // the curated type, id 2 (the same Member the `members` type also serves).
        $document = $this->fetchDocument('/posts/2/relationships/author');
        self::assertSame(['type' => 'public-members', 'id' => '2'], $document['data'] ?? null);
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aRelationshipMutationWithAWrongTypeIsRejected(): void
    {
        // A mutation sending the WRONG resource type — `{members}` where the relation
        // declares `public-members` — is a `409` resource-type conflict (the linkage
        // twin of core's create-path ResourceTypeUnacceptable). The bundle's validator
        // enforces the relation's declared related types: a linkage whose `type` is not
        // among them is rejected before the association is touched.
        $response = $this->handle('/posts/2/relationships/author', 'PATCH', [
            'data' => ['type' => 'members', 'id' => '1'],
        ]);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        $this->assertTypeConflict($response, '/data/type');

        // The wrong-type mutation did not persist: post 2 still has no author (an empty
        // to-one renders `data: null`).
        $document = $this->fetchDocument('/posts/2/relationships/author');
        self::assertArrayHasKey('data', $document);
        self::assertNull($document['data']);
    }

    // --- (6) the type guard on the whole-resource-write path -----------------

    #[Test]
    #[Group('spec:creating')]
    public function aWholeResourceWriteWithACorrectRelationshipTypeIsAccepted(): void
    {
        // POST a new post with an embedded `author` carrying the DECLARED
        // `public-members` type — accepted (the persister resolves the curated linkage
        // back to the stored Member), so the create succeeds and the author is set.
        $response = $this->handle('/posts', 'POST', [
            'data' => [
                'type' => 'posts',
                'attributes' => ['title' => 'Fresh'],
                'relationships' => [
                    'author' => ['data' => ['type' => 'public-members', 'id' => '1']],
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);
        $author = $relationships['author'] ?? null;
        self::assertIsArray($author);
        self::assertSame(['type' => 'public-members', 'id' => '1'], $author['data'] ?? null);
    }

    #[Test]
    #[Group('spec:creating')]
    public function aWholeResourceWriteWithAWrongRelationshipTypeIsRejected(): void
    {
        // POST a new post whose embedded `author` carries the WRONG `members` type
        // (the relation declares `public-members`) — the same `409` resource-type
        // conflict, but the pointer now locates the relationship in the write body
        // (`/data/relationships/author/data/type`), not the endpoint root.
        $response = $this->handle('/posts', 'POST', [
            'data' => [
                'type' => 'posts',
                'attributes' => ['title' => 'Bad author'],
                'relationships' => [
                    'author' => ['data' => ['type' => 'members', 'id' => '1']],
                ],
            ],
        ]);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        $this->assertTypeConflict($response, '/data/relationships/author/data/type');
    }

    // --- helpers -------------------------------------------------------------

    /**
     * Asserts the response's first error is the `409` resource-type conflict the
     * linkage type guard raises — the create-analog status + code core uses for a
     * wrong `data.type` (`RESOURCE_TYPE_UNACCEPTABLE`) — at the expected pointer.
     */
    private function assertTypeConflict(Response $response, string $pointer): void
    {
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        $error = $errors[0] ?? null;
        self::assertIsArray($error);

        self::assertSame('409', $error['status'] ?? null);
        self::assertSame('RESOURCE_TYPE_UNACCEPTABLE', $error['code'] ?? null);

        $source = $error['source'] ?? null;
        self::assertIsArray($source);
        self::assertSame($pointer, $source['pointer'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchDocument(string $path): array
    {
        $response = $this->handle($path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchResource(string $path): array
    {
        $data = $this->fetchDocument($path)['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $resource
     *
     * @return array<string, mixed>
     */
    private function attributesOf(array $resource): array
    {
        $attributes = $resource['attributes'] ?? null;
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }

    /**
     * @param array<string, mixed> $resource
     *
     * @return array<string, mixed>
     */
    private function relationshipsOf(array $resource): array
    {
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);

        /** @var array<string, mixed> $relationships */
        return $relationships;
    }

    /**
     * @param array<mixed> $included
     *
     * @return array<string, mixed>|null
     */
    private function findIncluded(array $included, string $type, string $id): ?array
    {
        foreach ($included as $resource) {
            if (!\is_array($resource)) {
                continue;
            }
            if (($resource['type'] ?? null) === $type && ($resource['id'] ?? null) === $id) {
                /** @var array<string, mixed> $resource */
                return $resource;
            }
        }

        return null;
    }
}
