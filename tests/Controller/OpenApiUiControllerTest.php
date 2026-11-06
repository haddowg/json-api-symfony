<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Controller;

use haddowg\JsonApiBundle\Controller\OpenApiUiController;
use haddowg\JsonApiBundle\OpenApi\Config\OpenApiUiRenderer;
use haddowg\JsonApiBundle\OpenApi\DocumentUrls;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * The ReDoc page the viewer renders for each supported major: the 3.x ES module with
 * telemetry off unless the app opts in, and the 2.x global script, each offering the
 * document's JSON and YAML downloads.
 */
#[Group('spec:openapi')]
final class OpenApiUiControllerTest extends TestCase
{
    private const string SPEC_URL = '/api/docs.json';

    private const string JSON_ONLY = '[{&quot;title&quot;:&quot;JSON&quot;,&quot;url&quot;:&quot;/api/docs.json&quot;}]';

    #[Test]
    public function redocDefaultsToTheThreeXModuleWithTelemetryOff(): void
    {
        $body = $this->render();

        self::assertSame('3.0.1', OpenApiUiController::REDOC_VERSION);
        self::assertStringContainsString('<redoc spec-url="/api/docs.json" download-urls="' . self::JSON_ONLY . '" disable-telemetry="true"></redoc>', $body);
        self::assertStringContainsString(
            '<script type="module" src="https://cdn.jsdelivr.net/npm/redoc@3.0.1/bundles/redoc.standalone.js" crossorigin></script>',
            $body,
        );
    }

    #[Test]
    public function anAppThatOptsIntoTelemetryGetsNoDisableAttribute(): void
    {
        $body = $this->render(telemetry: true);

        self::assertStringContainsString('<redoc spec-url="/api/docs.json" download-urls="' . self::JSON_ONLY . '"></redoc>', $body);
        self::assertStringNotContainsString('disable-telemetry', $body);
        self::assertStringContainsString('<script type="module" src="https://cdn.jsdelivr.net/npm/redoc@3.0.1/bundles/redoc.standalone.js"', $body);
    }

    #[Test]
    public function aTwoXVersionLoadsTheGlobalScript(): void
    {
        $body = $this->render(version: OpenApiUiController::REDOC_VERSION_2);

        self::assertSame('2.5.4', OpenApiUiController::REDOC_VERSION_2);
        self::assertStringContainsString('<div id="redoc-container"></div>', $body);
        self::assertStringContainsString(
            '<script src="https://cdn.jsdelivr.net/npm/redoc@2.5.4/bundles/redoc.standalone.js" crossorigin></script>',
            $body,
        );
        self::assertStringContainsString(
            'Redoc.init("/api/docs.json", { downloadUrls: [{"title":"JSON","url":"/api/docs.json"}] }, document.getElementById(\'redoc-container\'));',
            $body,
        );
        // 2.x starts itself on a <redoc> tag, which would render the page twice.
        self::assertStringNotContainsString('<redoc', $body);
        self::assertStringNotContainsString('type="module"', $body);
        self::assertStringNotContainsString('disable-telemetry', $body);
    }

    #[Test]
    public function bothMajorsOfferTheYamlDownloadWhenItIsServed(): void
    {
        $three = $this->render(yaml: true);
        self::assertStringContainsString(
            'download-urls="[{&quot;title&quot;:&quot;JSON&quot;,&quot;url&quot;:&quot;/api/docs.json&quot;},{&quot;title&quot;:&quot;YAML&quot;,&quot;url&quot;:&quot;/api/docs.yaml&quot;}]"',
            $three,
        );

        $two = $this->render(version: '2.5.4', yaml: true);
        self::assertStringContainsString(
            '{ downloadUrls: [{"title":"JSON","url":"/api/docs.json"},{"title":"YAML","url":"/api/docs.yaml"}] }',
            $two,
        );
    }

    #[Test]
    public function theDownloadUrlsAreEscapedForTheirContext(): void
    {
        $hostile = '/x"\'</script><b>&.yaml';

        $three = $this->render(yaml: true, yamlUrl: $hostile);
        self::assertStringNotContainsString('</script><b>', $three);
        self::assertStringContainsString('&quot;/x\\&quot;&apos;&lt;/script&gt;&lt;b&gt;&amp;.yaml&quot;', $three);
        \preg_match('/download-urls="([^"]*)"/', $three, $attribute);
        self::assertSame(
            [['title' => 'JSON', 'url' => self::SPEC_URL], ['title' => 'YAML', 'url' => $hostile]],
            \json_decode(\html_entity_decode($attribute[1] ?? '', \ENT_QUOTES | \ENT_HTML5), true),
        );

        $two = $this->render(version: '2.5.4', yaml: true, yamlUrl: $hostile);
        self::assertStringNotContainsString('</script><b>', $two);
        self::assertStringContainsString('"url":' . \json_encode($hostile, \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_HEX_AMP | \JSON_UNESCAPED_SLASHES), $two);
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

    private function render(string $version = OpenApiUiController::REDOC_VERSION, bool $telemetry = false, ?string $cdn = null, bool $yaml = false, string $yamlUrl = '/api/docs.yaml'): string
    {
        $urlGenerator = new class ($yamlUrl) implements UrlGeneratorInterface {
            public function __construct(private readonly string $yamlUrl) {}

            /**
             * @param array<string, mixed> $parameters
             */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return \str_starts_with($name, 'jsonapi.openapi.yaml.') ? $this->yamlUrl : OpenApiUiControllerTest::specUrl();
            }

            public function setContext(RequestContext $context): void {}

            public function getContext(): RequestContext
            {
                return new RequestContext();
            }
        };

        $controller = new OpenApiUiController(
            $urlGenerator,
            OpenApiUiRenderer::Redoc,
            cdn: $cdn,
            redocVersion: $version,
            redocTelemetry: $telemetry,
            urls: $yaml ? new DocumentUrls($urlGenerator, yaml: true) : null,
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
