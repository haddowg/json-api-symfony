<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Testing;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Gives a standard Symfony {@see \Symfony\Bundle\FrameworkBundle\Test\WebTestCase}
 * a {@see JsonApiBrowser} from the normal client-creation flow, so a JSON:API
 * functional test reads idiomatically:
 *
 * ```php
 * final class PlaylistTest extends WebTestCase
 * {
 *     use InteractsWithJsonApi;
 *
 *     public function test_owner_reads_their_playlists(): void
 *     {
 *         $client = static::createClient();          // a JsonApiBrowser
 *         $client->actingAs('ada@example.com')
 *             ->get('/playlists')
 *             ->assertFetchedMany();
 *     }
 * }
 * ```
 *
 * **How it swaps the client.** Symfony's `WebTestCase::createClient()` resolves the
 * `test.client` service (a {@see KernelBrowser}). The cleanest idiomatic swap is to
 * redefine that service's *class* to {@see JsonApiBrowser} — its constructor mirrors
 * `KernelBrowser`'s exactly, so it is a drop-in. But this bundle's own harness boots
 * imperative `MicroKernelTrait` test kernels (no shared `config/packages/test/` to
 * carry a service override), which makes editing the service fragile per kernel.
 * So the trait takes the documented alternative the bundle's own
 * {@see \haddowg\JsonApiBundle\Tests\Functional\JsonApiFunctionalTestCase::browser()}
 * uses: it **overrides `createClient()`** to construct a {@see JsonApiBrowser}
 * straight from the booted kernel. The standard `static::createClient()` ergonomics
 * are preserved — only the returned class differs.
 *
 * The browser disables kernel reboot in its constructor, so a write-then-read in one
 * test sees the write (the seed survives) without any further setup.
 *
 * @phpstan-require-extends KernelTestCase
 */
trait InteractsWithJsonApi
{
    private static ?JsonApiBrowser $jsonApiBrowser = null;

    /**
     * @var callable|null the error handler active before the kernel booted
     */
    private $jsonApiErrorHandler;

    /**
     * @var callable|null the exception handler active before the kernel booted
     */
    private $jsonApiExceptionHandler;

    /**
     * Boots the kernel and returns a {@see JsonApiBrowser} — the JSON:API drop-in for
     * the stock `WebTestCase::createClient()`. Same signature, same ergonomics.
     *
     * It boots with `debug => false` by default (matching the bundle's
     * production-fidelity convention: debug-only error meta is redacted exactly as in
     * production, and the kernel does not stream debug logs to stdout). Pass
     * `['debug' => true]` to opt back in.
     *
     * @param array<string, mixed> $options an array of options to pass to bootKernel
     * @param array<string, mixed> $server  default `$_SERVER` parameters for every request
     */
    protected static function createClient(array $options = [], array $server = []): JsonApiBrowser
    {
        if (static::$booted) {
            throw new \LogicException(\sprintf('Booting the kernel before calling "%s()" is not supported, the kernel should only be booted once.', __METHOD__));
        }

        $kernel = static::bootKernel($options + ['debug' => false]);

        return self::$jsonApiBrowser = new JsonApiBrowser($kernel, $server);
    }

    /**
     * The JSON:API client, created on first use over the booted kernel — the trait
     * accessor for tests that prefer a named getter to `static::createClient()`.
     */
    protected function jsonApiClient(): JsonApiBrowser
    {
        return self::$jsonApiBrowser ??= new JsonApiBrowser($this->bootedKernel());
    }

    /**
     * Snapshot the active error/exception handlers before the kernel boots and
     * installs Symfony's, so {@see restoreJsonApiHandlers()} can pop the stack back to
     * this baseline for PHPUnit's strict-mode handler check.
     */
    #[Before]
    protected function snapshotJsonApiHandlers(): void
    {
        self::$jsonApiBrowser = null;

        $this->jsonApiErrorHandler = \set_error_handler(null);
        \restore_error_handler();
        $this->jsonApiExceptionHandler = \set_exception_handler(null);
        \restore_exception_handler();
    }

    /**
     * Pop every error/exception handler the kernel pushed back to the snapshot, so the
     * global handler stack is balanced when the test ends.
     */
    #[After]
    protected function restoreJsonApiHandlers(): void
    {
        while (true) {
            $current = \set_error_handler(static fn(): bool => false);
            \restore_error_handler();
            if ($current === $this->jsonApiErrorHandler) {
                break;
            }
            \restore_error_handler();
        }

        while (true) {
            $current = \set_exception_handler(null);
            \restore_exception_handler();
            if ($current === $this->jsonApiExceptionHandler) {
                break;
            }
            \restore_exception_handler();
        }
    }

    private function bootedKernel(): KernelInterface
    {
        if (!static::$booted) {
            static::bootKernel(['debug' => false]);
        }
        $kernel = static::$kernel;
        \assert($kernel instanceof KernelInterface);

        return $kernel;
    }
}
