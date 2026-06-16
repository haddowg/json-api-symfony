<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The self-links-by-convention acceptance suite (core ADR 0054, bundle ADR 0047).
 * Core emits TWO spec-recommended (SHOULD) `self` links by convention, both derived
 * from ingredients the bundle already exposes (the resource's `baseUri`/`uriType`/`id`
 * and the request URI), so they are **provider-agnostic** — the in-memory `charts`
 * provider and the Doctrine `albums`/`tracks` providers render identical self URLs:
 *
 *  - a **resource-level** `data.links.self = {baseUri}/{uriType}/{id}` on every
 *    resource object (primary data AND `?include`'d resources AND a `201`-created
 *    resource), unless the type opts out;
 *  - a **top-level document** `links.self` = the request URI (`{baseUri}{path}` plus
 *    the percent-encoded query string when present) on every data/resource document
 *    (single, collection, related, relationship, meta) — but NOT error documents.
 *
 * The base URI here is the example's `default` server (`https://music.example`,
 * `config/packages/json_api.yaml`). On a paginated collection the page's own per-page
 * self wins (carrying the resolved `page[...]` params), so the top-level self reflects
 * the actual page, percent-encoded (spaces as `+`, brackets as `%5B`/`%5D`).
 *
 * The opt-out is witnessed on the `devices` type, whose `DeviceResource` overrides
 * `emitsSelfLink()` to return `false`: a device carries no `data.links.self`, while
 * the top-level document self still renders (the opt-out is resource-scoped).
 */
#[Group('spec:document-structure')]
final class SelfLinkTest extends MusicCatalogKernelTestCase
{
    private const string BASE_URI = 'https://music.example';

    // --- resource-level self -------------------------------------------------

    #[Test]
    public function aSingleResourceCarriesAResourceSelfLink(): void
    {
        // data.links.self = {baseUri}/{uriType}/{id}. For `albums` the uriType is the
        // type itself, so the resource self is /albums/1.
        $data = $this->dataOf($this->fetch('/albums/1'));

        self::assertSame(self::BASE_URI . '/albums/1', $this->selfOf($data));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anIncludedResourceAlsoCarriesAResourceSelfLink(): void
    {
        // The convention self link rides every resource object, including the
        // compound-document `included` members: /albums/1 default-includes its
        // artist, which carries its own /artists/1 self.
        $document = $this->fetch('/albums/1');

        $included = $document['included'] ?? null;
        self::assertIsArray($included);

        $artist = $included[0] ?? null;
        self::assertIsArray($artist);
        self::assertSame('artists', $artist['type'] ?? null);
        self::assertSame(self::BASE_URI . '/artists/1', $this->selfOf($artist));
    }

    #[Test]
    public function eachCollectionMemberCarriesItsOwnResourceSelfLink(): void
    {
        // Every resource object in a collection carries its own self.
        $data = $this->fetch('/tracks?sort=title')['data'] ?? null;
        self::assertIsArray($data);

        foreach ($data as $track) {
            self::assertIsArray($track);
            $id = $track['id'] ?? null;
            self::assertIsString($id);
            self::assertSame(self::BASE_URI . '/tracks/' . $id, $this->selfOf($track));
        }
    }

    #[Test]
    public function aStandaloneSerializerResolvesItsResourceSelfFromUriType(): void
    {
        // `charts` has no resource/entity — just a serializer + provider. Its
        // serializer implements UriTypeAwareInterface (uriType() === 'charts'), so the
        // convention self resolves /charts/{id} exactly as an entity-backed type does.
        $data = $this->dataOf($this->fetch('/charts/1'));

        self::assertSame('charts', $data['type'] ?? null);
        self::assertSame(self::BASE_URI . '/charts/1', $this->selfOf($data));
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function aCreatedResourceCarriesAResourceSelfLink(): void
    {
        // A 201-created resource carries the convention self for its freshly assigned
        // id (a genre's natural key), matching the Location header.
        $response = $this->handle('/genres', 'POST', [
            'data' => ['type' => 'genres', 'id' => 'shoegaze', 'attributes' => ['name' => 'Shoegaze']],
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->dataOf($response);
        self::assertSame(self::BASE_URI . '/genres/shoegaze', $this->selfOf($data));
        self::assertSame(self::BASE_URI . '/genres/shoegaze', $response->headers->get('Location'));
    }

    // --- top-level document self ---------------------------------------------

    #[Test]
    public function aSingleResourceDocumentCarriesABareRequestPathTopLevelSelf(): void
    {
        // An unpaginated document's top-level self is the bare request path.
        self::assertSame(self::BASE_URI . '/albums/1', $this->topLevelSelf($this->fetch('/albums/1')));
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aCollectionDocumentTopLevelSelfCarriesTheResolvedPageQuery(): void
    {
        // On a paginated collection the page's own per-page self wins as the top-level
        // self, carrying the RESOLVED page params percent-encoded (brackets as
        // %5B/%5D). The server default per-page is 15 here.
        self::assertSame(
            self::BASE_URI . '/tracks?page%5Bnumber%5D=1&page%5Bsize%5D=15',
            $this->topLevelSelf($this->fetch('/tracks')),
        );
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:fetching-pagination')]
    public function aFilteredCollectionTopLevelSelfPreservesTheFilterAndPageQuery(): void
    {
        // A filter param is preserved alongside the resolved page params, the whole
        // query rebuilt and percent-encoded (a literal space would be a '+').
        self::assertSame(
            self::BASE_URI . '/tracks?filter%5Btitle%5D=air&page%5Bnumber%5D=1&page%5Bsize%5D=15',
            $this->topLevelSelf($this->fetch('/tracks?filter[title]=air')),
        );
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    #[Group('spec:fetching-pagination')]
    public function aSortedPagedCollectionTopLevelSelfReflectsTheRequestedPage(): void
    {
        // The top-level self carries the requested page (number 1, size 2) and the
        // sort, alongside the navigation links.
        $document = $this->fetch('/tracks?sort=title&page[size]=2&page[number]=1');

        self::assertSame(
            self::BASE_URI . '/tracks?sort=title&page%5Bnumber%5D=1&page%5Bsize%5D=2',
            $this->topLevelSelf($document),
        );

        // The pagination links survive alongside the top-level self.
        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertArrayHasKey('next', $links);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelatedToOneDocumentTopLevelSelfIsTheRelatedEndpointPath(): void
    {
        // GET /tracks/1/album: the top-level self is the bare related-endpoint path,
        // while the rendered album still carries its own resource self /albums/1.
        $document = $this->fetch('/tracks/1/album');

        self::assertSame(self::BASE_URI . '/tracks/1/album', $this->topLevelSelf($document));
        self::assertSame(self::BASE_URI . '/albums/1', $this->selfOf($this->dataOf($document)));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function aRelatedToManyDocumentTopLevelSelfCarriesItsPageQuery(): void
    {
        // GET /albums/1/tracks is a paginated related collection (perPage 2), so its
        // top-level self carries the resolved page params on the related-endpoint path.
        self::assertSame(
            self::BASE_URI . '/albums/1/tracks?page%5Bnumber%5D=1&page%5Bsize%5D=2',
            $this->topLevelSelf($this->fetch('/albums/1/tracks')),
        );
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelationshipDocumentTopLevelSelfIsTheRelationshipEndpointPath(): void
    {
        // GET /tracks/1/relationships/album is a linkage document (no resource
        // objects); its top-level self is the relationship-endpoint path.
        $document = $this->fetch('/tracks/1/relationships/album');

        self::assertSame(self::BASE_URI . '/tracks/1/relationships/album', $this->topLevelSelf($document));
        // A linkage document holds identifiers, not resource objects, so no data self.
        self::assertSame(['type' => 'albums', 'id' => '1'], $document['data'] ?? null);
    }

    // --- the opt-out ----------------------------------------------------------

    #[Test]
    public function anOptedOutResourceHasNoResourceSelfButTheDocumentSelfStillRenders(): void
    {
        // DeviceResource::emitsSelfLink() returns false: a device carries NO
        // data.links.self, while the top-level document self (the request URI) is
        // unaffected — the opt-out is resource-scoped, not document-scoped.
        $id = $this->createDevice();

        $document = $this->fetch('/devices/' . $id);

        $data = $this->dataOf($document);
        self::assertArrayNotHasKey('links', $data, 'an opted-out resource emits no data.links.self');
        self::assertSame(self::BASE_URI . '/devices/' . $id, $this->topLevelSelf($document));
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function anOptedOutCreatedResourceAlsoEmitsNoResourceSelf(): void
    {
        // The opt-out holds on a 201-created resource too: no data.links.self, but the
        // Location header (which is not a self link) is still set.
        $response = $this->handle('/devices', 'POST', [
            'data' => ['type' => 'devices', 'attributes' => ['label' => 'Patio Speaker']],
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->dataOf($response);
        self::assertArrayNotHasKey('links', $data);
        self::assertNotNull($response->headers->get('Location'));
    }

    // --- helpers -------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $path): array
    {
        $response = $this->handle($path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->decode($response);
    }

    /**
     * The decoded primary `data` object of a single-resource document/response.
     *
     * @param array<string, mixed>|Response $documentOrResponse
     *
     * @return array<string, mixed>
     */
    private function dataOf(array|Response $documentOrResponse): array
    {
        $document = $documentOrResponse instanceof Response
            ? $this->decode($documentOrResponse)
            : $documentOrResponse;

        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * The `links.self` of a resource object.
     *
     * @param array<string, mixed> $resource
     */
    private function selfOf(array $resource): mixed
    {
        $links = $resource['links'] ?? null;
        self::assertIsArray($links);

        return $links['self'] ?? null;
    }

    /**
     * The top-level document `links.self`.
     *
     * @param array<string, mixed> $document
     */
    private function topLevelSelf(array $document): mixed
    {
        $links = $document['links'] ?? null;
        self::assertIsArray($links);

        return $links['self'] ?? null;
    }

    /**
     * Mints a `devices` row (app-generated ULID) and returns its wire id.
     */
    private function createDevice(): string
    {
        $response = $this->handle('/devices', 'POST', [
            'data' => ['type' => 'devices', 'attributes' => ['label' => 'Kitchen Speaker']],
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $id = $this->dataOf($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }
}
