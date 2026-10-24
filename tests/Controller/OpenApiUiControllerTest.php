<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Controller;

use haddowg\JsonApiBundle\Controller\OpenApiUiController;
use haddowg\JsonApiBundle\OpenApi\Config\OpenApiUiRenderer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * The ReDoc page the viewer renders for each supported major: the 3.x ES module with
 * telemetry off unless the app opts in, and the 2.x global script.
 */
#[Group('spec:openapi')]
final class OpenApiUiControllerTest extends TestCase
{
    private const string SPEC_URL = '/api/docs.json';

    #[Test]
    public function redocDefaultsToTheThreeXModuleWithTelemetryOff(): void
    {
        $body = $this->render();

        self::assertSame('3.0.1', OpenApiUiController::REDOC_VERSION);
        self::assertStringContainsString('<redoc spec-url="/api/docs.json" disable-telemetry="true"></redoc>', $body);
        self::assertStringContainsString(
            '<script type="module" src="https://cdn.jsdelivr.net/npm/redoc@3.0.1/bundles/redoc.standalone.js" crossorigin></script>',
            $body,
        );
    }

    #[Test]
    public function anAppThatOptsIntoTelemetryGetsNoDisableAttribute(): void
    {
        $body = $this->render(telemetry: true);

        self::assertStringContainsString('<redoc spec-url="/api/docs.json"></redoc>', $body);
        self::assertStringNotContainsString('disable-telemetry', $body);
        self::assertStringContainsString('<script type="module" src="https://cdn.jsdelivr.net/npm/redoc@3.0.1/bundles/redoc.standalone.js"', $body);
    }

    #[Test]
    public function aTwoXVersionLoadsTheGlobalScript(): void
    {
        $body = $this->render(version: OpenApiUiController::REDOC_VERSION_2);

        self::assertSame('2.5.4', OpenApiUiController::REDOC_VERSION_2);
        self::assertStringContainsString('<redoc spec-url="/api/docs.json"></redoc>', $body);
        self::assertStringContainsString(
            '<script src="https://cdn.jsdelivr.net/npm/redoc@2.5.4/bundles/redoc.standalone.js" crossorigin></script>',
            $body,
        );
        self::assertStringNotContainsString('type="module"', $body);
        self::assertStringNotContainsString('disable-telemetry', $body);
    }

    #[Test]
    public function aCustomCdnServesEitherMajor(): void
    {
        $three = $this->render(cdn: 'https://assets.example.test/redoc/');
        self::assertStringContainsString('<script type="module" src="https://assets.example.test/redoc/redoc.standalone.js" crossorigin></script>', $three);
        self::assertStringNotContainsString('cdn.jsdelivr.net', $three);

        $two = $this->render(version: '2.5.4', cdn: 'https://assets.example.test/redoc');
        self::assertStringContainsString('<script src="https://assets.example.test/redoc/redoc.standalone.js" crossorigin></script>', $two);
        self::assertStringNotContainsString('cdn.jsdelivr.net', $two);
    }

    private function render(string $version = OpenApiUiController::REDOC_VERSION, bool $telemetry = false, ?string $cdn = null): string
    {
        $controller = new OpenApiUiController(
            new class implements UrlGeneratorInterface {
                /**
                 * @param array<string, mixed> $parameters
                 */
                public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
                {
                    return OpenApiUiControllerTest::specUrl();
                }

                public function setContext(RequestContext $context): void {}

                public function getContext(): RequestContext
                {
                    return new RequestContext();
                }
            },
            OpenApiUiRenderer::Redoc,
            cdn: $cdn,
            redocVersion: $version,
            redocTelemetry: $telemetry,
        );

        $response = $controller(Request::create('/docs'));
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getContent();
    }

    public static function specUrl(): string
    {
        return self::SPEC_URL;
    }
}
