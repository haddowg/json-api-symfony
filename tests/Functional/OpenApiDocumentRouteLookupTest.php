<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\OpenApiMultiServerTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve\WarningLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerAggregate;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * How `describedby` finds the document when the app routes the OpenAPI controller itself
 * rather than importing the bundle's routes, and the warning when nothing serves it.
 */
final class OpenApiDocumentRouteLookupTest extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
{
    private const string ADMIN_POINTER = '#/paths/~1admin-items~1%7Bid%7D/get';

    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected function setUp(): void
    {
        $this->errorHandler = \set_error_handler(null);
        \restore_error_handler();
        $this->exceptionHandler = \set_exception_handler(null);
        \restore_exception_handler();
        WarningLogger::$warnings = [];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreHandlers();
        WarningLogger::$warnings = [];
    }

    #[Test]
    #[Group('spec:document-links')]
    public function linksPointAtTheAppsOwnDocumentRoutes(): void
    {
        $kernel = $this->boot(new OpenApiMultiServerTestKernel('test', false, docsRoutes: 'custom'));
        $this->warm($kernel);
        self::assertSame([], WarningLogger::$warnings);

        $admin = $this->document($kernel, '/admin/admin-items/1');
        self::assertSame(
            ['href' => 'https://admin.test/admin/admin-items/1', 'describedby' => '/api/v5/docs.json' . self::ADMIN_POINTER],
            $this->at($admin, 'links', 'self'),
        );
        self::assertSame(['href' => '/api/v5/docs.json' . self::ADMIN_POINTER], $this->at($admin, 'links', 'describedby'));

        $public = $this->document($kernel, '/public-items/1');
        self::assertSame('/api/docs.json#/paths/~1public-items~1%7Bid%7D/get', $this->at($public, 'links', 'self', 'describedby'));

        // The served document's link example points at the same route.
        $spec = $this->document($kernel, '/api/v5/docs.json');
        self::assertSame(
            '/api/v5/docs.json' . self::ADMIN_POINTER,
            $this->at($spec, 'components', 'schemas', 'LinkObject', 'example', 'describedby'),
        );

        $kernel->shutdown();
    }

    #[Test]
    #[Group('spec:document-links')]
    public function combinedModePointsEveryServerAtTheAppsOneDocumentRoute(): void
    {
        $kernel = $this->boot(new OpenApiMultiServerTestKernel('test', false, combined: true, docsRoutes: 'custom'));
        $this->warm($kernel);
        self::assertSame([], WarningLogger::$warnings);

        $admin = $this->document($kernel, '/admin/admin-items/1');
        self::assertSame('/api/docs.json' . self::ADMIN_POINTER, $this->at($admin, 'links', 'self', 'describedby'));

        $kernel->shutdown();
    }

    #[Test]
    #[Group('spec:document-links')]
    public function aConfiguredDocumentUrlStillWinsOverTheAppsRoute(): void
    {
        $kernel = $this->boot(new OpenApiMultiServerTestKernel('test', false, docsRoutes: 'custom', documentUrl: 'https://docs.example.test/{server}.json'));
        $this->warm($kernel);

        $admin = $this->document($kernel, '/admin/admin-items/1');
        self::assertSame('https://docs.example.test/admin.json' . self::ADMIN_POINTER, $this->at($admin, 'links', 'self', 'describedby'));

        $kernel->shutdown();
    }

    #[Test]
    #[Group('spec:document-links')]
    public function theWarmupWarnsOnceForEachServerNoRouteServes(): void
    {
        $kernel = $this->boot(new OpenApiMultiServerTestKernel('test', false, docsRoutes: 'none'));
        $this->warm($kernel);

        self::assertCount(2, WarningLogger::$warnings);
        self::assertStringContainsString('No route serves the OpenAPI document for server "default"', WarningLogger::$warnings[0]);
        self::assertStringContainsString('No route serves the OpenAPI document for server "admin"', WarningLogger::$warnings[1]);
        self::assertStringContainsString('json_api.openapi.document_url', WarningLogger::$warnings[1]);

        $admin = $this->document($kernel, '/admin/admin-items/1');
        self::assertSame(['href' => 'https://admin.test/admin/admin-items/1'], $this->at($admin, 'links', 'self'));
        $this->document($kernel, '/public-items/1');

        // Requests don't repeat it.
        self::assertCount(2, WarningLogger::$warnings);

        $kernel->shutdown();
    }

    #[Test]
    #[Group('spec:document-links')]
    public function theWarmupSkipsAServerWithNoJsonApiRoutesMounted(): void
    {
        $kernel = $this->boot(new OpenApiMultiServerTestKernel('test', false, docsRoutes: 'none', mountDefault: false));
        $this->warm($kernel);

        self::assertCount(1, WarningLogger::$warnings);
        self::assertStringContainsString('No route serves the OpenAPI document for server "admin"', WarningLogger::$warnings[0]);

        $kernel->shutdown();
    }

    #[Test]
    #[Group('spec:document-links')]
    public function combinedModeStillWarnsWhenAnyServerIsMounted(): void
    {
        $kernel = $this->boot(new OpenApiMultiServerTestKernel('test', false, combined: true, docsRoutes: 'none', mountDefault: false));
        $this->warm($kernel);

        self::assertCount(1, WarningLogger::$warnings);
        self::assertStringContainsString('No route serves the combined OpenAPI document', WarningLogger::$warnings[0]);

        $kernel->shutdown();
    }

    #[Test]
    #[Group('spec:document-links')]
    public function theWarmupStaysQuietWhenTheDocumentIsDeliberatelyNotExposed(): void
    {
        $kernel = $this->boot(new OpenApiMultiServerTestKernel('test', false, docsRoutes: 'none', exposed: false));
        $this->warm($kernel);

        self::assertSame([], WarningLogger::$warnings);

        $kernel->shutdown();
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
     * @return array<string, mixed>
     */
    private function document(KernelInterface $kernel, string $path): array
    {
        $response = $kernel->handle(Request::create($path, 'GET'), HttpKernelInterface::MAIN_REQUEST, true);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $decoded = \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function at(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
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
