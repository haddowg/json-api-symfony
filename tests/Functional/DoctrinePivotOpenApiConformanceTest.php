<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Testing\SchemaConformanceTrait;
use haddowg\JsonApiBundle\Testing\SchemaDocumentKind;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Responses that render `meta.pivot` validate against the generated response pivot
 * component (`<Type><Rel>Pivot`), which requires every readable pivot field and
 * admits `null` where the server guarantees no value. Doctrine only: the in-memory
 * provider holds no pivot data, so it renders no `meta.pivot` to check.
 *
 * `playlists.tracks` declares a nullable writable `weight`, a read-only `addedAt`, a
 * hidden `note`, a write-only `source`, a `curator` write-only by predicate and a
 * `label` hidden by predicate for one member, so the check covers each way a pivot
 * field reaches, or stays out of, the response.
 */
final class DoctrinePivotOpenApiConformanceTest extends JsonApiFunctionalTestCase
{
    use SchemaConformanceTrait;
    use SeedsDoctrinePivot;

    private const string BASE_URI = 'https://example.test';

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aRelationshipDocumentWithPivotMetaValidates(): void
    {
        $response = $this->handle('/playlists/1/relationships/tracks');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->assertResponseMatchesGeneratedSchema($response, 'playlists', SchemaDocumentKind::Relationship, 'tracks');
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aCompoundSingleDocumentWithPivotLinkageValidates(): void
    {
        $response = $this->handle('/playlists/1?include=tracks');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->assertResponseMatchesGeneratedSchema($response, 'playlists', SchemaDocumentKind::Single);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aCompoundCollectionDocumentWithPivotLinkageValidates(): void
    {
        $response = $this->handle('/playlists?include=tracks');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->assertResponseMatchesGeneratedSchema($response, 'playlists', SchemaDocumentKind::Collection);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aDefaultRenderedPivotLinkageValidates(): void
    {
        $response = $this->handle('/playlists/1');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->assertResponseMatchesGeneratedSchema($response, 'playlists', SchemaDocumentKind::Single);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aPivotFieldTheRowHoldsNoValueForRendersNullAndValidates(): void
    {
        // A new member added without a `weight` stores none; the response still
        // carries the key, as `null`, because the read pivot schema requires it.
        $response = $this->handle(
            self::BASE_URI . '/playlists/2/relationships/tracks',
            'POST',
            ['data' => [['type' => 'tracks', 'id' => '3', 'meta' => ['pivot' => ['position' => 5]]]]],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $response = $this->handle('/playlists/2/relationships/tracks');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $pivot = null;
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            if (($identifier['id'] ?? null) === '3') {
                $meta = $identifier['meta'] ?? null;
                self::assertIsArray($meta);
                $pivot = $meta['pivot'] ?? null;
            }
        }

        self::assertIsArray($pivot);
        self::assertSame(['position', 'weight', 'addedAt', 'label'], \array_keys($pivot));
        self::assertNull($pivot['weight']);

        $this->assertResponseMatchesGeneratedSchema($response, 'playlists', SchemaDocumentKind::Relationship, 'tracks');
    }
}
