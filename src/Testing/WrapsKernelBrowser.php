<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Testing;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Gives a test case a {@see JsonApiBrowser} without touching `createClient()`, for a
 * suite whose base test case already overrides `createClient()` to return its own
 * {@see KernelBrowser}:
 *
 * ```php
 * final class PlaylistTest extends AppWebTestCase   // its createClient() returns a KernelBrowser
 * {
 *     use WrapsKernelBrowser;
 *
 *     public function test_owner_reads_their_playlists(): void
 *     {
 *         $this->jsonApi(static::createClient())
 *             ->actingAs('ada@example.com')
 *             ->get('/playlists')
 *             ->assertFetchedMany();
 *     }
 * }
 * ```
 *
 * {@see jsonApi()} returns the same browser for the same client within a test, so
 * `$this->jsonApi($client)` can be called again to assert over the last response. The
 * trait also keeps the error/exception handler stack balanced for PHPUnit's strict mode,
 * as {@see InteractsWithJsonApi} does (which uses this trait).
 *
 * @phpstan-require-extends KernelTestCase
 */
trait WrapsKernelBrowser
{
    /**
     * @var \WeakMap<KernelBrowser, JsonApiBrowser>|null the browser built for each client this test
     */
    private ?\WeakMap $jsonApiBrowsers = null;

    /**
     * @var callable|null the error handler active before the kernel booted
     */
    private $jsonApiErrorHandler;

    /**
     * @var callable|null the exception handler active before the kernel booted
     */
    private $jsonApiExceptionHandler;

    /**
     * The {@see JsonApiBrowser} over `$client`'s kernel, built on first use (see
     * {@see JsonApiBrowser::wrap()}). A client that already is one is returned as is.
     */
    protected function jsonApi(KernelBrowser $client): JsonApiBrowser
    {
        if ($client instanceof JsonApiBrowser) {
            return $client;
        }

        $this->jsonApiBrowsers ??= new \WeakMap();

        return $this->jsonApiBrowsers[$client] ??= JsonApiBrowser::wrap($client);
    }

    /**
     * Snapshot the active error/exception handlers before the kernel boots and
     * installs Symfony's, so {@see restoreJsonApiHandlers()} can pop the stack back to
     * this baseline for PHPUnit's strict-mode handler check.
     */
    #[Before]
    protected function snapshotJsonApiHandlers(): void
    {
        $this->jsonApiBrowsers = null;

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
}
