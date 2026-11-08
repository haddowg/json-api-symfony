<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ActionDeclarations\ActionDeclarationKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\ActionDeclarations\FilteredGadget;
use haddowg\JsonApiBundle\Tests\Functional\App\ActionDeclarations\UnknownFilterGadgets;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An action taking filters on a single resource fails the container build, and one
 * listing a filter its type does not declare fails the warm-up a cold boot runs.
 */
#[Group('spec:actions')]
final class ActionFilterBuildTest extends KernelTestCase
{
    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected static function getKernelClass(): string
    {
        return ActionDeclarationKernel::class;
    }

    protected function setUp(): void
    {
        $this->errorHandler = \set_error_handler(null);
        \restore_error_handler();
        $this->exceptionHandler = \set_exception_handler(null);
        \restore_exception_handler();
    }

    #[Test]
    public function filtersOnAResourceScopeActionFailTheBuild(): void
    {
        $this->expectBuildError(FilteredGadget::class, 'Only a collection-scope action may take filters');
        $this->boot(FilteredGadget::class);
    }

    #[Test]
    public function aFilterTheTypeDoesNotDeclareFailsTheBuild(): void
    {
        $this->expectBuildError(UnknownFilterGadgets::class, 'takes the filter "colour", which the type does not declare');
        $this->boot(UnknownFilterGadgets::class);
    }

    /**
     * @param class-string $class
     */
    private function expectBuildError(string $class, string $message): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The #[AsJsonApiAction] on ' . $class . ' is invalid on server "default"');
        $this->expectExceptionMessage($message);
    }

    /**
     * @param class-string ...$actions
     */
    private function boot(string ...$actions): void
    {
        static::ensureKernelShutdown();
        ActionDeclarationKernel::$actions = \array_values($actions);
        $kernel = new ActionDeclarationKernel('test', false);
        $this->removeDir($kernel->getCacheDir());
        static::bootKernel(['debug' => false]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
        ActionDeclarationKernel::$actions = [];
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
