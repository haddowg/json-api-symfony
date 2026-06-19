<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\OpenApiPrefixedUiTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Prefixed-mount witness for the documentation viewer (design D6): the OpenAPI routes are
 * imported under `->prefix('/api')`, so the document lives at `/api/docs.json`. The viewer
 * must point at that prefixed URL — generated from the document route, not joined from the
 * front-controller base URL (which carries no routing prefix). This regression-guards the
 * earlier `getBaseUrl()`-only behaviour that produced a 404-ing `/docs.json` under a prefix.
 */
final class OpenApiPrefixedUiTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return OpenApiPrefixedUiTestKernel::class;
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theViewerPointsAtThePrefixedDocumentUrl(): void
    {
        $body = (string) $this->handle('/api/docs')->getContent();

        self::assertStringContainsString('url: "/api/docs.json"', $body);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theViewerAndTheDocumentShareThePrefixedMount(): void
    {
        self::assertSame(200, $this->handle('/api/docs')->getStatusCode());
        self::assertSame(200, $this->handle('/api/docs.json')->getStatusCode());

        // The unprefixed paths do not exist under a prefixed mount.
        self::assertSame(404, $this->handle('/docs')->getStatusCode());
        self::assertSame(404, $this->handle('/docs.json')->getStatusCode());
    }
}
