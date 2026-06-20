<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\EagerValidation\AncestorToManyProductResource;
use haddowg\JsonApiBundle\Tests\Functional\App\EagerValidation\BaseEagerProductResource;
use haddowg\JsonApiBundle\Tests\Functional\App\EagerValidation\LeafToManyProductResource;
use haddowg\JsonApiBundle\Tests\Functional\App\EagerValidation\SafeProductResource;
use haddowg\JsonApiBundle\Tests\Functional\App\EagerValidation\UnknownSegmentProductResource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The fail-loud eager-load validation acceptance suite (bundle ADR 0085), run
 * identically against the in-memory ({@see InMemoryEagerLoadValidationTest}) and
 * Doctrine ({@see DoctrineEagerLoadValidationTest}) kernels — the boot-time throw is
 * pure metadata, so it must fire identically on BOTH providers.
 *
 * Each case boots the provider kernel with ONE subject `products` resource (its single
 * flattened attribute's `on()` chain pins a single shape). The
 * {@see \haddowg\JsonApiBundle\Serializer\EagerLoadWarmer} is a NON-optional
 * `kernel.cache_warmer`, so booting a kernel with a cold cache runs `cache:warmup` and
 * the warmer DURING boot — exactly what `cache:clear` / deploy does. A malformed `on()`
 * chain therefore throws a developer-facing `\LogicException` from `bootKernel()` itself
 * (the build fails, never a runtime 500); a valid to-one chain boots clean.
 */
abstract class EagerLoadValidationConformanceTestCase extends KernelTestCase
{
    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    /**
     * Boots the provider kernel with `$subject` as its `products` resource (a fresh
     * cold-cache boot, so the non-optional eager-load warmer runs during warm-up).
     *
     * @param class-string<BaseEagerProductResource> $subject
     */
    abstract protected function bootWithSubject(string $subject): void;

    protected function setUp(): void
    {
        // Snapshot the active handlers: booting the kernel installs Symfony's, and
        // PHPUnit's strict mode flags any not restored (the booted-then-threw cases
        // still install them).
        $this->errorHandler = \set_error_handler(null);
        \restore_error_handler();
        $this->exceptionHandler = \set_exception_handler(null);
        \restore_exception_handler();
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aLeafToManySegmentThrowsAtWarmUp(): void
    {
        // `on('tags')`: a to-many leaf is not flattenable, so it throws.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/to-many/');
        $this->bootWithSubject(LeafToManyProductResource::class);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function anAncestorToManySegmentThrowsAtWarmUp(): void
    {
        // `on('tags.region')`: the ancestor `tags` is a to-many, so it throws even
        // though it is not the leaf.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/to-many/');
        $this->bootWithSubject(AncestorToManyProductResource::class);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function anUnknownSegmentThrowsAtWarmUp(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/unknown relation/');
        $this->bootWithSubject(UnknownSegmentProductResource::class);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aValidToOneChainDoesNotThrowAtWarmUp(): void
    {
        // `on('region.region')`: a multi-hop to-one chain (one hidden, one visible hop)
        // passes — the kernel boots clean (the warm-up validation accepts every to-one
        // chain).
        $this->bootWithSubject(SafeProductResource::class);
        self::assertNotNull(static::$kernel);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
        $this->restoreHandlers();
    }

    private function restoreHandlers(): void
    {
        // Pop every handler the booted (or booted-then-threw) kernel pushed, back to the
        // setUp snapshot, so PHPUnit's strict mode stays balanced.
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

    /**
     * Recursively removes a cache directory so the next boot is a cold-cache warm-up
     * (the non-optional eager-load warmer must re-run).
     */
    protected static function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->isDir()) {
                @\rmdir($file->getPathname());
            } else {
                @\unlink($file->getPathname());
            }
        }

        @\rmdir($dir);
    }
}
