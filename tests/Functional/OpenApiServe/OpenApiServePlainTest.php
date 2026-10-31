<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\OpenApiServe;

use haddowg\JsonApiBundle\OpenApi\ArtifactStore;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve\OpenApiServeTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;

/**
 * With no serve decorator registered the warmed artifact is served byte for byte, tagged
 * with the hash the warmer stored beside it (bundle ADR 0077).
 */
final class OpenApiServePlainTest extends OpenApiServeTestCase
{
    protected static function environment(): string
    {
        return 'plain';
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theWarmedArtifactIsServedUnchangedWithItsStoredHash(): void
    {
        $this->warm();
        $artifact = $this->store()->read('default');
        $hash = $this->store()->readHash('default');
        self::assertIsString($artifact);
        self::assertSame(ArtifactStore::hash($artifact), $hash);

        $response = $this->get('/docs.json');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($artifact, $response->getContent());
        self::assertSame(self::quoted($hash), $response->getEtag());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function anUnwarmedDocumentIsTaggedWithTheHashOfItsBody(): void
    {
        $response = $this->get('/docs.json');
        $body = (string) $response->getContent();

        // Whether or not an earlier test warmed this cache, the tag matches the bytes.
        self::assertSame(self::quoted(ArtifactStore::hash($body)), $response->getEtag());
        self::assertSame('https://catalog.test', self::arr(self::arr($this->json($response)['servers'] ?? null)[0] ?? null)['url'] ?? null);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aMatchingIfNoneMatchGets304WhetherStrongOrWeak(): void
    {
        $this->warm();
        $etag = $this->etagOf($this->get('/docs.json'));

        foreach ([$etag, 'W/' . $etag, '"other", ' . $etag, '*'] as $ifNoneMatch) {
            $response = $this->get('/docs.json', headers: ['If-None-Match' => $ifNoneMatch]);
            self::assertSame(304, $response->getStatusCode(), $ifNoneMatch);
            self::assertSame('', $response->getContent());
            self::assertSame($etag, $response->getEtag());
            self::assertSame(OpenApiServeTestKernel::CACHE_CONTROL, $this->cacheControl($response));
        }

        self::assertSame(200, $this->get('/docs.json', headers: ['If-None-Match' => '"stale"'])->getStatusCode());
    }

    #[Test]
    #[Group('spec:openapi')]
    public function cacheControlComesFromConfig(): void
    {
        self::assertSame(OpenApiServeTestKernel::CACHE_CONTROL, $this->cacheControl($this->get('/docs.json')));
        self::assertSame(OpenApiServeTestKernel::CACHE_CONTROL, $this->cacheControl($this->get('/schemas.json')));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theViewerPageCarriesTheConfiguredCacheControl(): void
    {
        $response = $this->get('/docs');
        self::assertSame(OpenApiServeTestKernel::CACHE_CONTROL, $this->cacheControl($response));
        // The session listener strips its opt-out header before the response leaves.
        self::assertFalse($response->headers->has(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function noDocumentationRouteSetsACookieOrVaries(): void
    {
        foreach (['/docs.json', '/schemas.json', '/docs'] as $path) {
            $response = $this->get($path);
            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertSame([], $response->headers->getCookies(), $path);
            self::assertFalse($response->headers->has('Set-Cookie'), $path);
            self::assertFalse($response->headers->has('Vary'), $path);
        }
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theSchemasAggregateIsServedUnchangedWithItsStoredHash(): void
    {
        $this->warm();
        $artifact = $this->store()->readSchemaAggregate('default');
        $hash = $this->store()->readSchemaAggregateHash('default');
        self::assertIsString($artifact);
        self::assertSame(ArtifactStore::hash($artifact), $hash);

        $response = $this->get('/schemas.json');
        self::assertSame($artifact, $response->getContent());
        self::assertSame(self::quoted($hash), $response->getEtag());

        $notModified = $this->get('/schemas.json', headers: ['If-None-Match' => 'W/' . self::quoted($hash)]);
        self::assertSame(304, $notModified->getStatusCode());
    }

    private function cacheControl(\Symfony\Component\HttpFoundation\Response $response): ?string
    {
        // Symfony normalises the directive order; compare as a set.
        $value = $response->headers->get('Cache-Control');
        if ($value === null) {
            return null;
        }
        $directives = \array_map('trim', \explode(',', $value));
        $expected = \array_map('trim', \explode(',', OpenApiServeTestKernel::CACHE_CONTROL));
        \sort($directives);
        \sort($expected);

        return $directives === $expected ? OpenApiServeTestKernel::CACHE_CONTROL : $value;
    }
}
