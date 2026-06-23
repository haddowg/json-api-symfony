<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\DoctrineServable\DoctrineServableKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\DoctrineServable\FilterFailWidgetResource;
use haddowg\JsonApiBundle\Tests\Functional\App\DoctrineServable\PivotFailWidgetResource;
use haddowg\JsonApiBundle\Tests\Functional\App\DoctrineServable\SafeWidgetResource;
use haddowg\JsonApiBundle\Tests\Functional\App\DoctrineServable\SortFailWidgetResource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Doctrine servability build-time guards (A3 + A7,
 * {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineServableWarmer}):
 *
 *  - **A3** — a `sortable()` / `filterable` column that does not resolve to a real
 *    Doctrine field/association (the common cause: a `computed()` field marked
 *    sortable, whose sort column defaults to the field name) must fail
 *    `cache:warmup`, not throw a `QueryException` 500 at request time.
 *  - **A7** — a pivot `belongsToMany` whose association entity cannot be discovered
 *    must fail `cache:warmup`, not throw a `\LogicException` on the first write.
 *
 * Booting a cold-cache Doctrine kernel runs the non-optional warmer during warm-up
 * (exactly what `cache:clear` / deploy does), so a misconfigured subject throws from
 * `bootKernel()` itself; a legit subject (a computed sortable field WITH a matching
 * `sorts()` override supplying a real column) boots clean — the guard validates the
 * RESOLVED column, so the override path is not false-flagged.
 */
final class DoctrineServableValidationTest extends KernelTestCase
{
    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected static function getKernelClass(): string
    {
        return DoctrineServableKernel::class;
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
    public function aComputedSortableFieldFailsWarmUp(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/sort "summary".*"widgets".*column "summary"/s');
        $this->bootWithSubject(SortFailWidgetResource::class);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aFilterOnANonColumnFailsWarmUp(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/filter "phantom".*"widgets".*column "phantom"/s');
        $this->bootWithSubject(FilterFailWidgetResource::class);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function anUnresolvablePivotFailsWarmUp(): void
    {
        // The PivotAssociationResolver's own message, fired at warm-up rather than
        // first write (only the timing moves).
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/pivot relation "gadgets".*through\(/s');
        $this->bootWithSubject(PivotFailWidgetResource::class);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aComputedSortableFieldWithAColumnBackedOverrideBootsClean(): void
    {
        // A computed() field marked sortable() that ALSO has a matching sorts()
        // override supplying a real column resolves to that real column — the guard
        // validates the RESOLVED column, so it does NOT throw.
        $this->bootWithSubject(SafeWidgetResource::class);
        self::assertNotNull(static::$kernel);
    }

    /**
     * @param class-string $subject
     */
    private function bootWithSubject(string $subject): void
    {
        static::ensureKernelShutdown();
        DoctrineServableKernel::$subjectResource = $subject;
        $kernel = new DoctrineServableKernel('test', false);
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
