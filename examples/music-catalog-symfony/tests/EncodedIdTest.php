<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Product;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Resource\ProductIdCodec;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RouterInterface;

/**
 * The custom resource-id encoding witness (bundle ADR 0038, backs `data-layer.md`):
 * the `products` type keys its entity by a database-generated integer that never
 * reaches the wire — the JSON:API `id` (and the URL) is an opaque `prod-…` token the
 * {@see ProductIdCodec} encodes/decodes. It proves the encode/decode round-trip on
 * the Doctrine kernel end to end:
 *
 *  - a read renders the encoded wire id (storage key != wire id);
 *  - GET by that wire id decodes it and finds the entity;
 *  - the wire id round-trips (the rendered id is the one the URL was built from);
 *  - a malformed id fails the route `{id}` requirement and `404`s at routing,
 *    before any handler runs (so it is not a JSON:API handler error);
 *  - a relationship write whose linkage carries an encoded `products` token resolves
 *    the right managed reference (the persister decodes the linkage id).
 */
#[Group('spec:crud')]
final class EncodedIdTest extends MusicCatalogKernelTestCase
{
    /**
     * @var array<int, string> storage int id => wire token, captured at seed time
     */
    private array $wireIds = [];

    #[Test]
    public function aReadRendersTheEncodedWireIdNotTheStorageKey(): void
    {
        $wire = $this->wireIds[1];
        self::assertStringStartsWith('prod-', $wire);
        self::assertNotSame('1', $wire, 'the wire id is the encoded token, not the integer storage key');

        $response = $this->handle('/products/' . $wire);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->dataOf($response);
        self::assertSame($wire, $data['id'] ?? null, 'the rendered id is the wire token');
        self::assertSame('Tour Poster', $this->nameOf($data));
    }

    #[Test]
    public function getByWireIdDecodesToTheStorageKeyAndFindsTheEntity(): void
    {
        // The Doctrine provider decodes the wire id to integer 2 before the find,
        // so the encoded token resolves the right row.
        $response = $this->handle('/products/' . $this->wireIds[2]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('Vinyl LP', $this->nameOf($this->dataOf($response)));
    }

    #[Test]
    public function theRouteIdSegmentIsConstrainedToTheCodecTokenSoAMalformedId404sAtRouting(): void
    {
        // matchAs('prod-[0-9a-f]+') stamps the {id} requirement on the show route, so
        // a value like `999` never matches and 404s at ROUTING — before any handler.
        // Reachability is asserted against the booted route collection rather than by
        // issuing a request to a non-matching path (which the framework logs as an
        // exception PHPUnit's strict mode flags risky — the convention
        // CapabilityCompositionTest/MultiServerTest follow).
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $show = $router->getRouteCollection()->get('jsonapi.products.show');
        self::assertNotNull($show);
        self::assertSame('prod-[0-9a-f]+', $show->getRequirement('id'));

        // The ROUTER itself rejects a malformed id (a bare integer): matching the path
        // throws a routing ResourceNotFoundException because the `{id}` requirement does
        // not admit it — so the failure is at routing, before any JSON:API handler runs.
        // (Matching directly, rather than issuing a request, keeps the framework from
        // logging the routing miss as output PHPUnit's strict mode flags risky.)
        try {
            $router->match('/products/999');
            self::fail('a malformed id must be rejected at routing');
        } catch (ResourceNotFoundException) {
            // expected: the route requirement gated the malformed id before any handler.
        }

        // A well-formed-but-unknown token, by contrast, MATCHES the route — routing
        // resolves it to the show handler, which then 404s (the decode succeeds; no row
        // holds that key) as a JSON:API error document, proving the malformed id above
        // is a routing rejection, not a handler one.
        $unknown = (new ProductIdCodec())->encode('424242');
        $matched = $router->match('/products/' . $unknown);
        self::assertSame('jsonapi.products.show', $matched['_route'] ?? null);

        $handlerMiss = $this->handle('/products/' . $unknown);
        self::assertSame(404, $handlerMiss->getStatusCode());
        self::assertStringContainsString(
            'application/vnd.api+json',
            (string) $handlerMiss->headers->get('Content-Type'),
            'a well-formed unknown id reaches the handler and renders a JSON:API 404',
        );
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function aStoreProvidedCreateAssignsTheIdAndRendersTheEncodedWireToken(): void
    {
        // The id is database-generated — a store-provided create (bundle ADR 0039): the
        // POST carries no id, the DB assigns the auto-increment integer, and the 201
        // body + Location render the ENCODED wire token for the freshly assigned key
        // (storage key != wire id, with no client-supplied id). The Create operation is
        // now advertised — a store-provided create is coherent.
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        self::assertNotNull(
            $router->getRouteCollection()->get('jsonapi.products.create'),
            'POST /products is now exposed for the store-provided create',
        );

        $response = $this->handle('/products', 'POST', [
            'data' => ['type' => 'products', 'attributes' => ['name' => 'Enamel Pin']],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->dataOf($response);
        $wire = $data['id'] ?? null;
        self::assertIsString($wire);
        self::assertStringStartsWith('prod-', $wire, 'the assigned id is rendered as the encoded wire token');
        self::assertSame('Enamel Pin', $this->nameOf($data));

        // The Location carries the same encoded token, and a follow-up GET by it
        // decodes back to the assigned storage key and finds the row.
        self::assertSame('https://music.example/products/' . $wire, $response->headers->get('Location'));

        $fetched = $this->handle('/products/' . $wire);
        self::assertSame(200, $fetched->getStatusCode(), (string) $fetched->getContent());
        self::assertSame('Enamel Pin', $this->nameOf($this->dataOf($fetched)));
    }

    #[Test]
    public function aRelationshipWriteDecodesAnEncodedLinkageId(): void
    {
        // Set product 2's `parent` to product 1 by PATCHing the relationship with the
        // ENCODED wire token. The persister decodes the linkage id (keyed by the
        // related type, `products`) to integer 1 before getReference, so the FK is
        // written to the right row.
        $response = $this->handle('/products/' . $this->wireIds[2] . '/relationships/parent', 'PATCH', [
            'data' => ['type' => 'products', 'id' => $this->wireIds[1]],
        ]);

        self::assertContains($response->getStatusCode(), [200, 204], (string) $response->getContent());

        // The related endpoint now resolves product 1 (rendered with its wire id).
        $related = $this->handle('/products/' . $this->wireIds[2] . '/parent');
        self::assertSame(200, $related->getStatusCode(), (string) $related->getContent());

        $data = $this->dataOf($related);
        self::assertSame($this->wireIds[1], $data['id'] ?? null);
        self::assertSame('Tour Poster', $this->nameOf($data));
    }

    protected function afterBoot(): void
    {
        parent::afterBoot();

        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $poster = new Product('Tour Poster');
        $vinyl = new Product('Vinyl LP');
        $entityManager->persist($poster);
        $entityManager->persist($vinyl);
        $entityManager->flush();

        $codec = new ProductIdCodec();
        \assert($poster->id !== null && $vinyl->id !== null);
        $this->wireIds = [
            $poster->id => $codec->encode($poster->id),
            $vinyl->id => $codec->encode($vinyl->id),
        ];

        // The fixtures use ids 1 and 2 in the assertions; AUTO on a fresh in-memory
        // SQLite schema assigns them in insertion order.
        \assert($poster->id === 1 && $vinyl->id === 2);
    }

    /**
     * The decoded document's primary `data` object, narrowed for offset access.
     *
     * @return array<string, mixed>
     */
    private function dataOf(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * The `data.attributes.name` of a primary `data` object.
     *
     * @param array<string, mixed> $data
     */
    private function nameOf(array $data): mixed
    {
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);

        return $attributes['name'] ?? null;
    }
}
