<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Testing;

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
 * A suite whose base test case already overrides `createClient()` can't use this trait;
 * use {@see WrapsKernelBrowser} and its `jsonApi($client)` accessor instead.
 *
 * @phpstan-require-extends KernelTestCase
 */
trait InteractsWithJsonApi
{
    use WrapsKernelBrowser;

    private static ?JsonApiBrowser $jsonApiBrowser = null;

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

    #[Before]
    protected function resetJsonApiClient(): void
    {
        self::$jsonApiBrowser = null;
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
