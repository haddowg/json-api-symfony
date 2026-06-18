<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\PolymorphicRelationTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The polymorphic related-endpoint witness: the related / relationship read
 * endpoints (plus compound `?include` and `page` slicing) over a polymorphic
 * to-one (`pinned`, a {@see \haddowg\JsonApi\Resource\Field\MorphTo}) and a
 * polymorphic to-many (`items`, a {@see \haddowg\JsonApi\Resource\Field\MorphToMany}).
 *
 * It proves the to-one serializer is resolved from the actual related object (not
 * `relatedTypes()[0]`) — board 1 pins a note, board 2 an image — and the to-many
 * renders its mixed-type members through a `PolymorphicSerializer` that
 * discriminates `notes` from `images` per member (ADR 0032). In-memory only:
 * the Doctrine provider throws "unsupported" for a polymorphic to-many (its
 * boundary unit test covers that).
 */
final class PolymorphicRelationTest extends JsonApiFunctionalTestCase
{
    private const string BASE_URI = 'https://example.test';

    protected static function getKernelClass(): string
    {
        return PolymorphicRelationTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aPolymorphicToOneRelatedEndpointRendersANoteType(): void
    {
        $document = $this->fetchDocument('/boards/1/pinned');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('notes', $data['type'] ?? null);
        self::assertSame('n1', $data['id'] ?? null);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('First note', $attributes['text'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aPolymorphicToOneRelatedEndpointRendersAnImageType(): void
    {
        // Board 2 pins an image, so the to-one serializer must be resolved from the
        // object, not relatedTypes()[0] (which is 'notes').
        $document = $this->fetchDocument('/boards/2/pinned');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('images', $data['type'] ?? null);
        self::assertSame('i1', $data['id'] ?? null);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertArrayHasKey('url', $attributes);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aPolymorphicToOneRelationshipEndpointRendersTheCorrectIdentifierType(): void
    {
        $document = $this->fetchDocument('/boards/2/relationships/pinned');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('images', $data['type'] ?? null);
        self::assertSame('i1', $data['id'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aPolymorphicToManyRelatedEndpointRendersMixedMemberTypes(): void
    {
        $document = $this->fetchDocument('/boards/1/items');

        $members = $this->members($document);
        self::assertCount(3, $members);

        self::assertSame('notes', $members[0]['type']);
        self::assertSame('n1', $members[0]['id']);
        self::assertSame('First note', $members[0]['attributes']['text'] ?? null);

        self::assertSame('images', $members[1]['type']);
        self::assertSame('i1', $members[1]['id']);
        self::assertArrayHasKey('url', $members[1]['attributes']);

        self::assertSame('notes', $members[2]['type']);
        self::assertSame('n2', $members[2]['id']);
        self::assertSame('Second note', $members[2]['attributes']['text'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function withCountCountsAPolymorphicToManyMixedSet(): void
    {
        // items is a countable() polymorphic to-many; the in-memory provider counts
        // the mixed member set (board 1: n1, i1, n2 = 3), so ?withCount=items emits
        // meta.total on the items relationship object (bundle ADR 0052). `?withCount`
        // is gated behind the Relationship Counts profile, so the read negotiates it.
        $response = $this->handle(self::BASE_URI . '/boards/1?withCount=items', extraServer: [
            'HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . \haddowg\JsonApi\Schema\Profile\CountableProfile::URI . '"',
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);

        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);

        $items = $relationships['items'] ?? null;
        self::assertIsArray($items);

        $meta = $items['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertSame(3, $meta['total'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aPolymorphicToManyRelationshipEndpointRendersMixedIdentifiers(): void
    {
        $document = $this->fetchDocument('/boards/1/relationships/items');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $identifiers = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            $identifiers[] = [$identifier['type'] ?? null, $identifier['id'] ?? null];
        }

        self::assertSame(
            [['notes', 'n1'], ['images', 'i1'], ['notes', 'n2']],
            $identifiers,
        );
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    #[Group('spec:fetching-relationships')]
    public function aPolymorphicToManyIncludeRendersMixedIncludedResources(): void
    {
        $document = $this->fetchDocument('/boards/1?include=items');

        $included = $document['included'] ?? null;
        self::assertIsArray($included);

        $byKey = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            $type = $resource['type'] ?? null;
            $id = $resource['id'] ?? null;
            self::assertIsString($type);
            self::assertIsString($id);
            $attributes = $resource['attributes'] ?? null;
            self::assertIsArray($attributes);
            $byKey[$type . ':' . $id] = $attributes;
        }

        self::assertArrayHasKey('notes:n1', $byKey);
        self::assertArrayHasKey('images:i1', $byKey);
        self::assertArrayHasKey('notes:n2', $byKey);

        self::assertSame('First note', $byKey['notes:n1']['text'] ?? null);
        self::assertArrayHasKey('url', $byKey['images:i1']);
        self::assertSame('Second note', $byKey['notes:n2']['text'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function aPolymorphicToManyRelatedCollectionPaginates(): void
    {
        // The MorphToMany carries a per-relation PagePaginator: page 1 of size 1 of
        // the mixed [n1, i1, n2] collection is exactly the first member (n1), with
        // page meta — `page` slices even though a polymorphic to-many carries no
        // shared filter/sort vocabulary.
        $document = $this->fetchDocument('/boards/1/items?page[size]=1&page[number]=1');

        $members = $this->members($document);
        self::assertCount(1, $members);
        self::assertSame('notes', $members[0]['type']);
        self::assertSame('n1', $members[0]['id']);

        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertArrayHasKey('page', $meta);
        self::assertIsArray($meta['page']);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aFilterOnAPolymorphicToOneRelatedEndpointIs400(): void
    {
        // A polymorphic to-one (`pinned`, a MorphTo) has no single related resource and
        // so no shared filter vocabulary — ANY filter key is unrecognised, mirroring the
        // polymorphic to-many's 400 (bundle ADR 0068 follow-up #1). Today it was silently
        // swallowed (200).
        $response = $this->handle(self::BASE_URI . '/boards/1/pinned?filter[name]=x');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['parameter' => 'filter[name]'], $this->firstError($response)['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aFilterOnAPolymorphicToOneRelationshipEndpointIs400(): void
    {
        // The relationship-linkage endpoint mirrors the related endpoint: a filter on a
        // polymorphic to-one is the same unrecognised-key 400 (follow-up #1).
        $response = $this->handle(self::BASE_URI . '/boards/1/relationships/pinned?filter[name]=x');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['parameter' => 'filter[name]'], $this->firstError($response)['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aFilterOnAPolymorphicToOneRelatedEndpointWithNoPinnedTargetIs400(): void
    {
        // The 400 is gated on the requested filter being present, NOT on a target
        // existing: a filter on an empty polymorphic to-one still 400s (follow-up #1).
        // Board 3 pins nothing.
        $response = $this->handle(self::BASE_URI . '/boards/3/pinned?filter[name]=x');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['parameter' => 'filter[name]'], $this->firstError($response)['source'] ?? null);
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aRelatedQueryFilterOnAPolymorphicToOnePrimaryRequestIs400(): void
    {
        // Addressing a polymorphic to-one with a relatedQuery filter from a primary
        // request under the profile is a 400 too: a polymorphic to-one is not a
        // windowable nor a (monomorphic) filterable to-one path, so the batcher's
        // path validation rejects it as an unrecognised relatedQuery path — never a
        // silent swallow (follow-up #1).
        $response = $this->handle(
            self::BASE_URI . '/boards/1?relatedQuery[pinned][filter][name]=x',
            extraServer: ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . \haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile::URI . '"'],
        );

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            ['parameter' => 'relatedQuery[pinned]'],
            $this->firstError($response)['source'] ?? null,
        );
    }

    // --- helpers ---------------------------------------------------------------

    /**
     * Fetches `$path` and returns the decoded document, asserting a 200 JSON:API
     * response.
     *
     * @return array<string, mixed>
     */
    protected function fetchDocument(string $path): array
    {
        $response = $this->handle(self::BASE_URI . $path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->decode($response);
    }

    /**
     * The first error object of the decoded error document `$response` carries.
     *
     * @return array<string, mixed>
     */
    private function firstError(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $first = $errors[0] ?? null;
        self::assertIsArray($first);

        /** @var array<string, mixed> $first */
        return $first;
    }

    /**
     * The document's primary collection members, each normalised to
     * `['type' => …, 'id' => …, 'attributes' => [...]]`, in document order.
     *
     * @param array<string, mixed> $document
     *
     * @return list<array{type: string, id: string, attributes: array<string, mixed>}>
     */
    private function members(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $members = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $type = $resource['type'] ?? null;
            $id = $resource['id'] ?? null;
            self::assertIsString($type);
            self::assertIsString($id);
            $attributes = $resource['attributes'] ?? [];
            self::assertIsArray($attributes);
            $members[] = ['type' => $type, 'id' => $id, 'attributes' => $attributes];
        }

        return $members;
    }
}
