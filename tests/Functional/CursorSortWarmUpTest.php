<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql\KeysetDqlDoctrineTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A cursor-paged collection whose sort can't key the page fails `cache:warmup`, where a
 * request ordering by it would otherwise answer `500`.
 */
final class CursorSortWarmUpTest extends KernelTestCase
{
    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected static function getKernelClass(): string
    {
        return KeysetDqlDoctrineTestKernel::class;
    }

    protected function setUp(): void
    {
        $this->errorHandler = \set_error_handler(null);
        \restore_error_handler();
        $this->exceptionHandler = \set_exception_handler(null);
        \restore_exception_handler();
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aSortWithoutAKeysetValueFailsWarmUp(): void
    {
        // The league's teams page by cursor too, and are checked first.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/related collection of relation "teams" on type "leagues" on server "default" pages by cursor, but its sort "unkeyable".*KeysetSortInterface/s');
        $this->boot('unkeyable');
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aDoctrineSortWhoseArmCannotKeyTheCursorFailsWarmUp(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/collection of (type "teams"|relation "teams" on type "leagues") on server "default" pages by cursor, but .*DoctrineDataProvider cannot order a cursor page by its sort "topScore".*DoctrineKeysetSortArmInterface/s');
        $this->boot('orderOnly');
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function keyedSortsBootClean(): void
    {
        $this->boot('keyed');
        self::assertNotNull(static::$kernel);
    }

    private function boot(string $variant): void
    {
        static::ensureKernelShutdown();
        KeysetDqlDoctrineTestKernel::$variant = $variant;
        $kernel = new KeysetDqlDoctrineTestKernel('test', false);
        $this->removeDir($kernel->getCacheDir());
        static::bootKernel(['debug' => false]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
        KeysetDqlDoctrineTestKernel::$variant = 'keyed';
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
