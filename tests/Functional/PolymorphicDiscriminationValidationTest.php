<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\PolyValidation\PolyValidationKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The polymorphic-discrimination build-time guard (guard A5,
 * {@see \haddowg\JsonApiBundle\Server\ServableResourceWarmer}): a polymorphic
 * relation whose candidate serializer does NOT override `getType()` is a silent
 * catch-all that mis-serializes its siblings' members — so it must fail
 * `cache:warmup` (the build), never a runtime mis-serialization.
 *
 * Booting a cold-cache kernel runs the non-optional servability warmer during
 * warm-up (exactly what `cache:clear` / deploy does), so the unsafe configuration
 * throws a developer-facing `\LogicException` from `bootKernel()` itself; the safe
 * configuration boots clean. A5 is provider-agnostic, so one in-memory kernel is the
 * witness.
 */
final class PolymorphicDiscriminationValidationTest extends KernelTestCase
{
    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected static function getKernelClass(): string
    {
        return PolyValidationKernel::class;
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
    public function aNonDiscriminatingPolymorphicCandidateFailsWarmUp(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/polymorphic relationship "pinned".*catch-all-items.*getType/s');
        $this->boot(safe: false);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function discriminatingPolymorphicCandidatesBootClean(): void
    {
        $this->boot(safe: true);
        self::assertNotNull(static::$kernel);
    }

    private function boot(bool $safe): void
    {
        static::ensureKernelShutdown();
        PolyValidationKernel::$safe = $safe;
        $kernel = new PolyValidationKernel('test', false);
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
