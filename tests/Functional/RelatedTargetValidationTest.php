<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\OpenApi\RelatedTypeNotRegistered;
use haddowg\JsonApiBundle\Tests\Functional\App\RelatedTargetValidation\RelatedTargetValidationKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The related-endpoint-target build-time guard
 * ({@see \haddowg\JsonApiBundle\Server\ServableResourceWarmer}): a relation exposing
 * `GET /{type}/{id}/{rel}` to a type the server does not register has no serializer
 * to render the response and no field inventory to describe it, so it must fail
 * `cache:warmup` (the build) rather than 500 on the first request and refuse the
 * OpenAPI export later.
 *
 * Booting a cold-cache kernel runs the non-optional warmer during warm-up (exactly
 * what `cache:clear` / deploy does), so the unsafe configuration throws from
 * `bootKernel()` itself; declaring the relation linkage-only boots clean.
 */
final class RelatedTargetValidationTest extends KernelTestCase
{
    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected static function getKernelClass(): string
    {
        return RelatedTargetValidationKernel::class;
    }

    protected function setUp(): void
    {
        $this->errorHandler = \set_error_handler(null);
        \restore_error_handler();
        $this->exceptionHandler = \set_exception_handler(null);
        \restore_exception_handler();
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aRelatedEndpointAimedAtAnUnregisteredTypeFailsWarmUp(): void
    {
        $this->expectException(RelatedTypeNotRegistered::class);
        // The message names the parent type, the relation, the related type and the
        // configured server, then offers the three ways out.
        $this->expectExceptionMessageMatches('/"crates" on type "shelves".*not registered on server "default".*withoutRelatedEndpoint/s');
        $this->boot(safe: false);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aLinkageOnlyRelationToAnUnregisteredTypeBootsClean(): void
    {
        $this->boot(safe: true);
        self::assertNotNull(static::$kernel);
    }

    private function boot(bool $safe): void
    {
        static::ensureKernelShutdown();
        RelatedTargetValidationKernel::$safe = $safe;
        $kernel = new RelatedTargetValidationKernel('test', false);
        $this->removeDir($kernel->getCacheDir());
        static::bootKernel(['debug' => false]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
        $this->restoreHandlers();
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

    private function removeDir(string $dir): void
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
