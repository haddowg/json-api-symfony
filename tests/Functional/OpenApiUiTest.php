<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Controller\OpenApiUiController;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\OpenApiTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Slice-5 documentation-viewer witness (design §6, D6 — bundle ADR 0079): boots the
 * {@see OpenApiTestKernel} (Swagger renderer, the default) and asserts `GET /docs`
 * renders a Swagger UI page wired to the pinned CDN assets and pointed at the configured
 * `/docs.json` spec URL. The renderer switch, CDN override and the disabled/expose gates
 * are exercised by their own kernels.
 */
final class OpenApiUiTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return OpenApiTestKernel::class;
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theUiRouteRendersSwaggerHtml(): void
    {
        $response = $this->handle('/docs');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));

        $body = (string) $response->getContent();
        // The Swagger UI bootstrap markup + script.
        self::assertStringContainsString('<div id="swagger-ui">', $body);
        self::assertStringContainsString('SwaggerUIBundle(', $body);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theSwaggerPageLoadsThePinnedCdnAssets(): void
    {
        $body = (string) $this->handle('/docs')->getContent();

        $base = 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@' . OpenApiUiController::SWAGGER_UI_VERSION;
        self::assertStringContainsString($base . '/swagger-ui.css', $body);
        self::assertStringContainsString($base . '/swagger-ui-bundle.js', $body);
        self::assertStringContainsString($base . '/swagger-ui-standalone-preset.js', $body);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theSwaggerPagePointsAtTheConfiguredSpecUrl(): void
    {
        $body = (string) $this->handle('/docs')->getContent();

        // The spec URL is the configured json path (default /docs.json), JSON-embedded
        // into the SwaggerUIBundle config so it can never break out of the script.
        self::assertStringContainsString('url: "/docs.json"', $body);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theUiRouteIsExposedAlongsideTheDocumentRoute(): void
    {
        // Both the viewer and the document it points at are reachable under the same mount.
        self::assertSame(200, $this->handle('/docs')->getStatusCode());
        self::assertSame(200, $this->handle('/docs.json')->getStatusCode());
    }
}
