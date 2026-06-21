<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Testing\JsonApiBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Shared scaffolding for the functional suites: boots the kernel a subclass
 * names (via {@see KernelTestCase::getKernelClass()}), issues JSON:API `GET`s
 * through it, decodes documents, and keeps the global error/exception-handler
 * stack balanced for PHPUnit's strict mode (booting and handling installs
 * Symfony's handlers).
 *
 * Extends {@see KernelTestCase} so Foundry's PHPUnit extension can resolve its
 * configuration from the test container when a suite seeds through factories.
 */
abstract class JsonApiFunctionalTestCase extends KernelTestCase
{
    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    private ?JsonApiBrowser $browser = null;

    /**
     * Hook for data-layer setup that needs the booted container (e.g. creating
     * the Doctrine schema and seeding it).
     */
    protected function afterBoot(): void {}

    protected function setUp(): void
    {
        // Snapshot the active error/exception handlers: booting the kernel
        // installs Symfony's, and PHPUnit's strict mode flags any not restored.
        $this->errorHandler = \set_error_handler(null);
        \restore_error_handler();
        $this->exceptionHandler = \set_exception_handler(null);
        \restore_exception_handler();

        static::bootKernel(['debug' => false]);
        $this->afterBoot();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->browser = null;
        $this->restoreHandlers();
    }

    /**
     * @param array<string, mixed>|null $body         a JSON:API document to send (POST/PATCH);
     *                                                 null sends no body (GET/DELETE)
     * @param array<string, string>     $extraServer additional `$_SERVER` entries (e.g.
     *                                                 `HTTP_AUTHORIZATION` for a firewall
     *                                                 under test, or a custom request header)
     */
    protected function handle(string $path, string $method = 'GET', ?array $body = null, array $extraServer = []): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        // The default Accept is the bare JSON:API media type; an `extraServer`
        // HTTP_ACCEPT (e.g. one carrying a negotiated `profile` media-type parameter)
        // overrides it, so a caller can drive content negotiation.
        $server = $extraServer + ['HTTP_ACCEPT' => 'application/vnd.api+json'];
        $content = null;
        if ($body !== null) {
            $server['CONTENT_TYPE'] = 'application/vnd.api+json';
            $content = \json_encode($body, \JSON_THROW_ON_ERROR);
        }

        $request = Request::create($path, $method, server: $server, content: $content);

        // catch: true is the production path — exceptions route through
        // kernel.exception, where the bundle's ExceptionListener renders
        // JSON:API error documents (the 400 filter/sort tests depend on it).
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);

        // The kernel installs Symfony's error/exception handlers while handling;
        // pop them back to the snapshot so PHPUnit's strict-handler check sees a
        // balanced stack at the end of the test.
        $this->restoreHandlers();

        return $response;
    }

    /**
     * Issues a request with a **raw** (non-JSON:API) body and content type — the path
     * a {@see \haddowg\JsonApiBundle\Action\ActionInput::Raw} upload action takes (a
     * `multipart/form-data` / blob body that is not `application/vnd.api+json`). The
     * response `Accept` stays the JSON:API media type so response negotiation resolves;
     * the request content-type assertion is what the Raw action relaxes.
     *
     * @param array<string, string> $extraServer additional `$_SERVER` entries (e.g.
     *                                            `HTTP_AUTHORIZATION` for a firewall)
     */
    protected function handleRaw(string $path, string $content, string $contentType = 'application/octet-stream', string $method = 'POST', array $extraServer = []): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $server = $extraServer + [
            'HTTP_ACCEPT' => 'application/vnd.api+json',
            'CONTENT_TYPE' => $contentType,
        ];

        $request = Request::create($path, $method, server: $server, content: $content);

        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);

        $this->restoreHandlers();

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        $content = (string) $response->getContent();
        $decoded = \json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * A lazily-built {@see JsonApiBrowser} over the one booted kernel — the fluent
     * successor to {@see handle()}/{@see decode()}. It reuses the kernel (reboot
     * disabled in the browser ctor) so an in-memory seed survives across a
     * write-then-read in a single test.
     */
    protected function browser(): JsonApiBrowser
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        return $this->browser ??= new JsonApiBrowser($kernel);
    }

    /**
     * Pops every error/exception handler the kernel pushed, back to the snapshot
     * taken in setUp, so the global handler stack is balanced. Protected so a
     * subclass that issues its own `$kernel->handle()` (e.g. the atomic suite's
     * `POST /operations`) can rebalance the stack the same way.
     */
    protected function restoreHandlers(): void
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
