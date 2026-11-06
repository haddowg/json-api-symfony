<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\OpenApi\DocumentUrls;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\OpenApiMultiServerTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerAggregate;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The OpenAPI document served as YAML: its routes per server and in combined mode, the
 * configured path, the enabled switch and the expose gate, routes an app declares by hand,
 * and the document URLs the viewer and an app's own page use.
 */
#[Group('spec:openapi')]
final class OpenApiYamlServingTest extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
{
    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected function setUp(): void
    {
        $this->errorHandler = \set_error_handler(null);
        \restore_error_handler();
        $this->exceptionHandler = \set_exception_handler(null);
        \restore_exception_handler();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreHandlers();
    }

    #[Test]
    public function eachServersDocumentIsServedAsYamlEqualToItsJson(): void
    {
        $kernel = $this->boot(new OpenApiMultiServerTestKernel('test', false));

        foreach (['/docs' => 'JSON:API', '/admin/docs' => 'JSON:API (admin)'] as $base => $title) {
            $yaml = $this->get($kernel, $base . '.yaml');
            $json = $this->get($kernel, $base . '.json');

            self::assertSame(200, $yaml->getStatusCode(), (string) $yaml->getContent());
            self::assertSame('application/yaml', $yaml->headers->get('Content-Type'));
            self::assertSame('application/json', $json->headers->get('Content-Type'));
            self::assertYamlMatchesJson($json, $yaml);
            self::assertSame($title, $this->parse($yaml)->info->title ?? null);

            self::assertNotNull($yaml->getEtag());
            self::assertNotSame($json->getEtag(), $yaml->getEtag());
            self::assertSame(304, $this->get($kernel, $base . '.yaml', ['HTTP_IF_NONE_MATCH' => (string) $yaml->getEtag()])->getStatusCode());
            self::assertSame(200, $this->get($kernel, $base . '.yaml', ['HTTP_IF_NONE_MATCH' => (string) $json->getEtag()])->getStatusCode());
        }

        $kernel->shutdown();
    }

    #[Test]
    public function combinedModeServesTheOneDocumentAsYamlWhereTheJsonIs(): void
    {
        $kernel = $this->boot(new OpenApiMultiServerTestKernel('test', false, combined: true));

        $yaml = $this->get($kernel, '/docs.yaml');
        self::assertSame(200, $yaml->getStatusCode());
        self::assertYamlMatchesJson($this->get($kernel, '/docs.json'), $yaml);
        self::assertSame(404, $this->get($kernel, '/admin/docs.yaml')->getStatusCode());

        $kernel->shutdown();
    }

    #[Test]
    public function theYamlPathIsConfigurable(): void
    {
        $kernel = $this->boot(new OpenApiMultiServerTestKernel('test', false, yaml: ['path' => '/openapi.yaml']));

        $yaml = $this->get($kernel, '/openapi.yaml');
        self::assertSame(200, $yaml->getStatusCode());
        self::assertYamlMatchesJson($this->get($kernel, '/docs.json'), $yaml);
        self::assertSame(404, $this->get($kernel, '/docs.yaml')->getStatusCode());
        self::assertSame('/openapi.yaml', $this->urls($kernel)->yaml());

        $kernel->shutdown();
    }

    #[Test]
    public function disabledYamlHasNoRouteAndAHandDeclaredOneIsNotFound(): void
    {
        $kernel = $this->boot(new OpenApiMultiServerTestKernel('test', false, docsRoutes: 'bundle', yaml: ['enabled' => false]));
        self::assertNotContains('/docs.yaml', $this->paths($kernel));
        self::assertNotContains('/{server}/docs.yaml', $this->paths($kernel));
        self::assertSame(404, $this->get($kernel, '/docs.yaml')->getStatusCode());
        self::assertNull($this->urls($kernel)->yaml());
        self::assertSame([['title' => 'JSON', 'url' => '/docs.json']], $this->urls($kernel)->downloads());
        $kernel->shutdown();

        $custom = $this->boot(new OpenApiMultiServerTestKernel('test', false, docsRoutes: 'custom_yaml', yaml: ['enabled' => false]));
        self::assertSame(404, $this->get($custom, '/api/v5/docs.yaml')->getStatusCode());
        self::assertSame(200, $this->get($custom, '/api/v5/docs.json')->getStatusCode());

        $viewer = (string) $this->get($custom, '/api/v5/docs')->getContent();
        self::assertStringContainsString('download-urls="[{&quot;title&quot;:&quot;JSON&quot;,&quot;url&quot;:&quot;/api/v5/docs.json&quot;}]"', $viewer);
        $custom->shutdown();
    }

    #[Test]
    public function theExposeGateHoldsTheYamlDocumentAsItDoesTheJson(): void
    {
        $hidden = $this->boot(new OpenApiMultiServerTestKernel('test', false, exposed: false));
        self::assertNotContains('/docs.yaml', $this->paths($hidden));
        self::assertNotContains('/docs.json', $this->paths($hidden));
        $hidden->shutdown();

        // A route the app declares by hand is still held to it.
        $custom = $this->boot(new OpenApiMultiServerTestKernel('test', false, docsRoutes: 'custom_yaml', exposed: false));
        self::assertSame(404, $this->get($custom, '/api/v5/docs.yaml')->getStatusCode());
        self::assertNull($this->urls($custom)->yaml('admin'));
        $custom->shutdown();

        // kernel.debug opens the gate.
        $debug = $this->boot(new OpenApiMultiServerTestKernel('test', true, exposed: false));
        self::assertSame(200, $this->get($debug, '/docs.yaml')->getStatusCode());
        $debug->shutdown();
    }

    #[Test]
    public function aHandDeclaredYamlRouteServesItsServerDefault(): void
    {
        $kernel = $this->boot(new OpenApiMultiServerTestKernel('test', false, docsRoutes: 'custom_yaml'));
        $this->warm($kernel);

        $json = $this->get($kernel, '/api/v5/docs.json');
        $yaml = $this->get($kernel, '/api/v5/docs.yaml');
        self::assertSame(200, $yaml->getStatusCode(), (string) $yaml->getContent());
        self::assertSame('application/yaml', $yaml->headers->get('Content-Type'));
        self::assertYamlMatchesJson($json, $yaml);
        self::assertSame('JSON:API (admin)', $this->parse($yaml)->info->title ?? null);
        self::assertNotSame($json->getEtag(), $yaml->getEtag());
        self::assertSame(304, $this->get($kernel, '/api/v5/docs.yaml', ['HTTP_IF_NONE_MATCH' => (string) $yaml->getEtag()])->getStatusCode());

        // The default server's route sets no server.
        self::assertYamlMatchesJson($this->get($kernel, '/api/docs.json'), $this->get($kernel, '/api/docs.yaml'));

        // Links still point into the JSON document.
        $item = \json_decode((string) $this->get($kernel, '/admin/admin-items/1')->getContent(), false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $item);
        $href = $item->links->describedby->href ?? null;
        self::assertIsString($href);
        self::assertStringStartsWith('/api/v5/docs.json#', $href);

        $urls = $this->urls($kernel);
        self::assertSame('/api/v5/docs.json', $urls->json('admin'));
        self::assertSame('/api/v5/docs.yaml', $urls->yaml('admin'));
        self::assertSame('/api/docs.json', $urls->json());
        self::assertSame('/api/docs.yaml', $urls->yaml());

        $kernel->shutdown();
    }

    #[Test]
    public function aHandDeclaredViewerOffersItsServersDownloads(): void
    {
        $kernel = $this->boot(new OpenApiMultiServerTestKernel('test', false, docsRoutes: 'custom_yaml'));

        $viewer = (string) $this->get($kernel, '/api/v5/docs')->getContent();
        self::assertStringContainsString('<redoc spec-url="/api/v5/docs.json" download-urls="[{&quot;title&quot;:&quot;JSON&quot;,&quot;url&quot;:&quot;/api/v5/docs.json&quot;},{&quot;title&quot;:&quot;YAML&quot;,&quot;url&quot;:&quot;/api/v5/docs.yaml&quot;}]"', $viewer);

        $kernel->shutdown();
    }

    #[Test]
    public function theDocumentUrlsFollowTheBundlesRoutes(): void
    {
        $kernel = $this->boot(new OpenApiMultiServerTestKernel('test', false));

        $urls = $this->urls($kernel);
        self::assertSame('/docs.json', $urls->json());
        self::assertSame('/docs.yaml', $urls->yaml());
        self::assertSame('/admin/docs.json', $urls->json('admin'));
        self::assertSame('/admin/docs.yaml', $urls->yaml('admin'));
        self::assertSame([['title' => 'JSON', 'url' => '/admin/docs.json'], ['title' => 'YAML', 'url' => '/admin/docs.yaml']], $urls->downloads('admin'));

        // Swagger UI, the default renderer, is unchanged.
        $swagger = (string) $this->get($kernel, '/docs')->getContent();
        self::assertStringContainsString('url: "/docs.json",', $swagger);
        self::assertStringNotContainsString('docs.yaml', $swagger);
        self::assertStringNotContainsString('download', $swagger);

        $kernel->shutdown();
    }

    private static function assertYamlMatchesJson(Response $json, Response $yaml): void
    {
        $fromJson = \json_decode((string) $json->getContent(), false, 512, \JSON_THROW_ON_ERROR);
        $fromYaml = Yaml::parse((string) $yaml->getContent(), Yaml::PARSE_OBJECT_FOR_MAP);

        self::assertSame(
            \json_encode($fromJson, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
            \json_encode($fromYaml, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
        );
    }

    private function parse(Response $yaml): \stdClass
    {
        $parsed = Yaml::parse((string) $yaml->getContent(), Yaml::PARSE_OBJECT_FOR_MAP);
        self::assertInstanceOf(\stdClass::class, $parsed);

        return $parsed;
    }

    private function boot(OpenApiMultiServerTestKernel $kernel): OpenApiMultiServerTestKernel
    {
        $kernel->boot();

        return $kernel;
    }

    private function warm(KernelInterface $kernel): void
    {
        $warmer = $kernel->getContainer()->get('cache_warmer');
        self::assertInstanceOf(CacheWarmerAggregate::class, $warmer);
        $warmer->enableOptionalWarmers();
        $warmer->warmUp($kernel->getCacheDir());
    }

    /**
     * @param array<string, string> $server
     */
    private function get(KernelInterface $kernel, string $path, array $server = []): Response
    {
        return $kernel->handle(Request::create($path, 'GET', server: $server), HttpKernelInterface::MAIN_REQUEST, true);
    }

    private function urls(KernelInterface $kernel): DocumentUrls
    {
        $urls = $kernel->getContainer()->get(DocumentUrls::class);
        self::assertInstanceOf(DocumentUrls::class, $urls);

        return $urls;
    }

    /**
     * @return list<string>
     */
    private function paths(KernelInterface $kernel): array
    {
        $router = $kernel->getContainer()->get('router');
        \assert($router instanceof RouterInterface);

        return \array_values(\array_map(static fn($route): string => $route->getPath(), $router->getRouteCollection()->all()));
    }

    private function restoreHandlers(): void
    {
        while (true) {
            $current = \set_error_handler(static fn(): bool => false);
            \restore_error_handler();
            if ($current === $this->errorHandler) {
                break;
            }
            \restore_error_handler();
        }

        while (true) {
            $current = \set_exception_handler(null);
            \restore_exception_handler();
            if ($current === $this->exceptionHandler) {
                break;
            }
            \restore_exception_handler();
        }
    }
}
