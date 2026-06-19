<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\OpenApiUiRedocTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The renderer-switch + CDN-override + custom-path witness (design D6/§11): the viewer
 * configured `renderer: redoc`, a custom `cdn`, and a non-default `path` renders a ReDoc
 * page (not Swagger) from the custom asset origin at the custom path.
 */
final class OpenApiUiRedocTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return OpenApiUiRedocTestKernel::class;
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theConfiguredRendererIsRedocAtTheConfiguredPath(): void
    {
        $response = $this->handle(OpenApiUiRedocTestKernel::UI_PATH);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));

        $body = (string) $response->getContent();
        // ReDoc markup, not Swagger UI (one renderer, not both).
        self::assertStringContainsString('<redoc spec-url="/docs.json">', $body);
        self::assertStringNotContainsString('SwaggerUIBundle', $body);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theCdnOverrideRetargetsTheAssetOrigin(): void
    {
        $body = (string) $this->handle(OpenApiUiRedocTestKernel::UI_PATH)->getContent();

        // The asset loads from the configured cdn override, not the pinned default.
        self::assertStringContainsString(OpenApiUiRedocTestKernel::CDN . '/redoc.standalone.js', $body);
        self::assertStringNotContainsString('cdn.jsdelivr.net', $body);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theDefaultDocsPathIsNotRegisteredWhenTheUiPathIsCustom(): void
    {
        // The viewer mounts at the configured /api-docs, so the default /docs 404s.
        self::assertSame(404, $this->handle('/docs')->getStatusCode());
    }
}
