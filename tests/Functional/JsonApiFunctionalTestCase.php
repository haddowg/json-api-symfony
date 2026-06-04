<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

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

        $this->restoreHandlers();
    }

    protected function handle(string $path): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $request = Request::create($path, 'GET', server: [
            'HTTP_ACCEPT' => 'application/vnd.api+json',
        ]);

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
     * Pops every error/exception handler the kernel pushed, back to the snapshot
     * taken in setUp, so the global handler stack is balanced.
     */
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
