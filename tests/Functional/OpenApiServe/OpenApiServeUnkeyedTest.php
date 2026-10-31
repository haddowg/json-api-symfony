<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\OpenApiServe;

use haddowg\JsonApiBundle\OpenApi\ArtifactStore;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve\FeatureGateDecorator;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve\UnkeyedDecorator;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve\UnkeyedSchemaDecorator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * One serve decorator without a cache key makes the ETag a hash of the served body, even
 * though the others have keys.
 */
final class OpenApiServeUnkeyedTest extends OpenApiServeTestCase
{
    protected static function environment(): string
    {
        return 'unkeyed';
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theEtagIsTheHashOfTheBody(): void
    {
        $response = $this->get('/docs.json', 'one.test');
        $this->assertValidOpenApi($response);

        self::assertSame(self::quoted(ArtifactStore::hash((string) $response->getContent())), $response->getEtag());
        self::assertSame('Served to one.test', self::arr($this->json($response)['info'] ?? null)['description'] ?? null);
        self::assertSame(0, FeatureGateDecorator::$keyed);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aMatchingIfNoneMatchStillGets304AfterDecorating(): void
    {
        $etag = $this->etagOf($this->get('/docs.json', 'one.test'));

        $response = $this->get('/docs.json', 'one.test', ['If-None-Match' => 'W/' . $etag]);
        self::assertSame(304, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame(2, UnkeyedDecorator::$decorated);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theSchemasAggregateFallsBackToTheBodyHashToo(): void
    {
        $response = $this->get('/schemas.json', 'one.test');
        self::assertSame(self::quoted(ArtifactStore::hash((string) $response->getContent())), $response->getEtag());
        self::assertSame('one.test', self::arr($this->json($response)['products'] ?? null)['x-served-to'] ?? null);

        $notModified = $this->get('/schemas.json', 'one.test', ['If-None-Match' => $this->etagOf($response)]);
        self::assertSame(304, $notModified->getStatusCode());
        self::assertSame(2, UnkeyedSchemaDecorator::$decorated);
    }
}
