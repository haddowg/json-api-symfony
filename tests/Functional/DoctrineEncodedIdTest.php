<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CogEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\HexIdEncoder;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\VaultEntity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RouterInterface;

/**
 * The custom resource-id encoding contract on the Doctrine kernel (bundle ADR 0038):
 * the `cogs` type keys its entity by an integer storage key that never reaches the
 * wire — the `id` (and URL) is an opaque `cog-…` token. Encoding is **Doctrine-only**
 * (the in-memory provider has no encoder), so this proves the encode/decode round-trip
 * over the Doctrine provider/persister and asserts an encoder-less type on the SAME
 * provider is unaffected (no encoder => wire == storage):
 *
 *  - a read renders the encoded wire id (storage key != wire id), and GET by it decodes
 *    to the storage key and finds the entity;
 *  - the route `{id}` is constrained to the codec token, so a malformed id 404s at
 *    routing before any handler runs;
 *  - a relationship write whose linkage carries an encoded `cogs` token decodes it to
 *    resolve the right managed reference;
 *  - the encoder-less `vaults` type renders wire == storage.
 */
#[Group('spec:crud')]
final class DoctrineEncodedIdTest extends JsonApiFunctionalTestCase
{
    /**
     * @var array<int, string> storage int id => wire token
     */
    private array $wireIds = [];

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }

    #[Test]
    public function aReadRendersTheEncodedWireIdAndGetByItDecodesToTheStorageKey(): void
    {
        $wire = $this->wireIds[1];
        self::assertStringStartsWith('cog-', $wire);
        self::assertNotSame('1', $wire, 'the wire id is the encoded token, not the integer storage key');

        $response = $this->handle('/cogs/' . $wire);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->dataOf($response);
        self::assertSame($wire, $data['id'] ?? null, 'the rendered id is the wire token, which round-trips');
        self::assertSame('Sprocket', $this->nameOf($data));
    }

    #[Test]
    public function theRouteIdSegmentIsConstrainedSoAMalformedId404sAtRouting(): void
    {
        // Asserted against the booted route collection rather than by issuing a
        // request to a non-matching path (which the framework logs as an exception
        // PHPUnit's strict mode flags risky — the convention the example suites follow).
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $show = $router->getRouteCollection()->get('jsonapi.cogs.show');
        self::assertNotNull($show);
        self::assertSame('cog-[0-9a-f]+', $show->getRequirement('id'));

        // The ROUTER itself rejects a malformed id (a bare integer): matching the path
        // throws a routing ResourceNotFoundException because the `{id}` requirement does
        // not admit it — so the failure happens at routing, before any JSON:API handler
        // is invoked. (Matching directly, rather than issuing a request, keeps the
        // framework from logging the routing miss as output PHPUnit's strict mode flags.)
        try {
            $router->match('/cogs/999');
            self::fail('a malformed id must be rejected at routing');
        } catch (ResourceNotFoundException) {
            // expected: the route requirement gated the malformed id before any handler.
        }

        // A well-formed-but-unknown token, by contrast, MATCHES the route — routing
        // resolves it to the show handler, which then 404s (decode succeeds; no row
        // holds that key) as a JSON:API error document. This contrast proves the
        // malformed id above is a routing rejection, not a handler one.
        $matched = $router->match('/cogs/' . (new HexIdEncoder())->encode('987654'));
        self::assertSame('jsonapi.cogs.show', $matched['_route'] ?? null);

        $miss = $this->handle('/cogs/' . (new HexIdEncoder())->encode('987654'));
        self::assertSame(404, $miss->getStatusCode());
        self::assertStringContainsString('application/vnd.api+json', (string) $miss->headers->get('Content-Type'));
    }

    #[Test]
    public function aRelationshipWriteDecodesAnEncodedLinkageId(): void
    {
        // Set cog 2's parent to cog 1 via the relationship endpoint, supplying the
        // ENCODED token. The persister decodes the linkage id (keyed by `cogs`) to
        // integer 1 before getReference, so the FK is written to the right row.
        $response = $this->handle('/cogs/' . $this->wireIds[2] . '/relationships/parent', 'PATCH', [
            'data' => ['type' => 'cogs', 'id' => $this->wireIds[1]],
        ]);
        self::assertContains($response->getStatusCode(), [200, 204], (string) $response->getContent());

        $related = $this->handle('/cogs/' . $this->wireIds[2] . '/parent');
        self::assertSame(200, $related->getStatusCode(), (string) $related->getContent());

        $data = $this->dataOf($related);
        self::assertSame($this->wireIds[1], $data['id'] ?? null);
        self::assertSame('Sprocket', $this->nameOf($data));
    }

    #[Test]
    public function aCreateWithAnEncodedClientIdRoundTrips(): void
    {
        // POST a new cog carrying an ENCODED client wire id. Core decodes it to the
        // integer storage key 5 on create (the entity holds the storage key, exactly
        // like a read entity), the persister flushes it, and the response re-encodes
        // it to the same wire id — so a server-generated UUID is never fed to the
        // codec (the core decode-only-on-client-id contract).
        $codec = new HexIdEncoder();
        $wire = $codec->encode('5');

        $response = $this->handle('/cogs', 'POST', [
            'data' => ['type' => 'cogs', 'id' => $wire, 'attributes' => ['name' => 'Cam']],
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->dataOf($response);
        self::assertSame($wire, $data['id'] ?? null, 'the created id renders as the wire token');
        self::assertSame('Cam', $this->nameOf($data));

        // The stored key differs from the wire id: the row was persisted under integer 5.
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $stored = $entityManager->find(CogEntity::class, 5);
        self::assertInstanceOf(CogEntity::class, $stored);
        self::assertSame(5, $stored->id);
        self::assertNotSame((string) $stored->id, $wire, 'the storage key is the integer, not the wire token');

        // GET by the wire id decodes and finds the row just created.
        $fetched = $this->handle('/cogs/' . $wire);
        self::assertSame(200, $fetched->getStatusCode(), (string) $fetched->getContent());
        self::assertSame($wire, $this->dataOf($fetched)['id'] ?? null);
    }

    #[Test]
    public function aRelationshipWriteWithAnUndecodableLinkageIdIsNotA500(): void
    {
        // A structurally-plausible but undecodable linkage id ('cog-zzz' — the codec
        // hex-decode rejects it) must NOT pass the raw wire string to getReference
        // (which would build an int-PK proxy that TypeErrors on init → 500). The
        // persister decodes the linkage id and, on a null, raises a clean 404.
        $response = $this->handle('/cogs/' . $this->wireIds[2] . '/relationships/parent', 'PATCH', [
            'data' => ['type' => 'cogs', 'id' => 'cog-zzz'],
        ]);

        self::assertLessThan(500, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('application/vnd.api+json', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function aToOneRelationFilterNullsAnExcludedEncodedParent(): void
    {
        // Cog 1's parent is cog 2 (Flywheel). A non-matching `filter[name]` on the
        // related endpoint excludes it, so the to-one renders `data: null` — the
        // single-probe nulling path (relatedToOneMatches) on an ENCODED-ID parent,
        // proving the encoded `cog-…` parent id does not break the lookup (bundle ADR
        // 0068 follow-up #4).
        $response = $this->handle('/cogs/' . $this->wireIds[1] . '/parent?filter[name]=Nonexistent');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $decoded = $this->decode($response);
        self::assertArrayHasKey('data', $decoded);
        self::assertNull($decoded['data'], 'the excluded parent renders data: null');

        // A MATCHING filter keeps the encoded parent (the lookup did not erroneously
        // null a present, matching target).
        $kept = $this->handle('/cogs/' . $this->wireIds[1] . '/parent?filter[name]=Flywheel');
        self::assertSame(200, $kept->getStatusCode(), (string) $kept->getContent());
        self::assertSame($this->wireIds[2], $this->dataOf($kept)['id'] ?? null);
    }

    #[Test]
    public function aRelatedQueryFilterNullsAnExcludedEncodedParentOnTheIncludePath(): void
    {
        // The BATCHED nulling path on an encoded-id parent: GET /cogs?include=parent with
        // relatedQuery[parent][filter][name]=<non-matching> over the page. Cog 1's parent
        // (cog 2) is excluded, so its linkage is nulled AND cog 2 is omitted from
        // included[] — proving parentWireId() keys (encoder-aware) agree with the
        // serializer's getId(), so the encoded parent is not erroneously left intact nor
        // every parent wrongly nulled (bundle ADR 0068 follow-up #4).
        $response = $this->handle(
            '/cogs?include=parent&relatedQuery[parent][filter][name]=Nonexistent',
            extraServer: ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . \haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile::URI . '"'],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->decode($response);

        // Cog 1's `parent` linkage is nulled.
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $sawCog1 = false;
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            if (($resource['id'] ?? null) === $this->wireIds[1]) {
                $sawCog1 = true;
                $relationships = $resource['relationships'] ?? null;
                self::assertIsArray($relationships);
                $parent = $relationships['parent'] ?? null;
                self::assertIsArray($parent);
                self::assertArrayHasKey('data', $parent);
                self::assertNull($parent['data'], "cog 1's excluded parent linkage is nulled");
            }
        }
        self::assertTrue($sawCog1, 'cog 1 was rendered in the primary collection');

        // The excluded parent (cog 2) is omitted from included[].
        $included = $document['included'] ?? [];
        self::assertIsArray($included);
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            self::assertNotSame($this->wireIds[2], $resource['id'] ?? null, 'the nulled parent is omitted from included[]');
        }
    }

    #[Test]
    public function aMatchingRelatedQueryFilterKeepsTheEncodedParentLinkageOnTheIncludePath(): void
    {
        // The matching-filter counterpart: a MATCHING relatedQuery filter keeps cog 1's
        // encoded parent (cog 2) linkage intact — the encoder-aware batch key matched the
        // present target rather than nulling everything (follow-up #4). The linkage (not
        // included[]) is the witness here: cog 2 is itself a primary collection member of
        // the self-referential `cogs`, so the spec never duplicates it into included[];
        // what proves the encoded key agreed is that the linkage survived.
        $response = $this->handle(
            '/cogs?include=parent&relatedQuery[parent][filter][name]=Flywheel',
            extraServer: ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . \haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile::URI . '"'],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->decode($response);
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $sawCog1 = false;
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            if (($resource['id'] ?? null) === $this->wireIds[1]) {
                $sawCog1 = true;
                $relationships = $resource['relationships'] ?? null;
                self::assertIsArray($relationships);
                $parent = $relationships['parent'] ?? null;
                self::assertIsArray($parent);
                self::assertSame(
                    ['type' => 'cogs', 'id' => $this->wireIds[2]],
                    $parent['data'] ?? null,
                    "cog 1's matching encoded parent linkage is kept",
                );
            }
        }
        self::assertTrue($sawCog1, 'cog 1 was rendered in the primary collection');
    }

    #[Test]
    public function anEncoderLessTypeOnTheSameProviderRendersWireEqualsStorage(): void
    {
        // `vaults` declares no encoder, so the SAME Doctrine provider treats the wire
        // id as the storage key directly. The vault's id is store-provided (an `AUTO`
        // integer), so the seeded row gets id 1 and GET /vaults/1 finds it — the wire
        // id equals the integer storage key with no codec in between.
        $id = $this->seedVault('unaffected');

        $response = $this->handle('/vaults/' . $id);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($id, $this->dataOf($response)['id'] ?? null);
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $cog2 = new CogEntity(2, 'Flywheel');
        // Cog 1's parent is cog 2 — a self-referential to-one whose target carries an
        // ENCODED `cog-…` wire id, so the to-one nulling path (bundle ADR 0068) can be
        // exercised on an encoded-id parent (follow-up #4).
        $cog1 = new CogEntity(1, 'Sprocket', $cog2);
        $entityManager->persist($cog2);
        $entityManager->persist($cog1);
        $entityManager->flush();
        $entityManager->clear();

        $codec = new HexIdEncoder();
        $this->wireIds = [1 => $codec->encode('1'), 2 => $codec->encode('2')];
    }

    /**
     * Persists a vault with a store-provided id and returns that id as the wire
     * string the encoder-less type renders (wire == storage).
     */
    private function seedVault(string $secret): string
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $vault = new VaultEntity(null, $secret);
        $entityManager->persist($vault);
        $entityManager->flush();
        $id = (string) $vault->id;
        $entityManager->clear();

        return $id;
    }

    /**
     * The decoded document's primary `data` object, narrowed for offset access.
     *
     * @return array<string, mixed>
     */
    private function dataOf(Response $response): array
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
