<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ActionDeclarations\ActionDeclarationKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\ActionDeclarations\DeleteGadget;
use haddowg\JsonApiBundle\Tests\Functional\App\ActionDeclarations\ExportAllCamelGadget;
use haddowg\JsonApiBundle\Tests\Functional\App\ActionDeclarations\ExportAllGadget;
use haddowg\JsonApiBundle\Tests\Functional\App\ActionDeclarations\ExportGadget;
use haddowg\JsonApiBundle\Tests\Functional\App\ActionDeclarations\ExportGadgets;
use haddowg\JsonApiBundle\Tests\Functional\App\ActionDeclarations\PurgeGadget;
use haddowg\JsonApiBundle\Tests\Functional\App\ActionDeclarations\ReservedExtensionGadget;
use haddowg\JsonApiBundle\Tests\Functional\App\ActionDeclarations\SyncGadget;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Action declarations whose operation ids would collide, or whose safety or extensions
 * are invalid, fail the container build, naming the handler class.
 */
#[Group('spec:actions')]
final class ActionDeclarationBuildTest extends KernelTestCase
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
    public function oneActionPathAtBothScopesFailsTheBuild(): void
    {
        $this->expectBuildError(ExportGadgets::class, 'declares the "export" action at both the resource and the collection scope');
        $this->boot(ExportGadget::class, ExportGadgets::class);
    }

    #[Test]
    public function anActionNamedLikeAnOperationFailsTheBuild(): void
    {
        $this->expectBuildError(DeleteGadget::class, 'is named like the "delete" operation');
        $this->boot(DeleteGadget::class);
    }

    #[Test]
    public function actionIdsThatFlattenToOneNameFailTheBuild(): void
    {
        $this->expectBuildError(ExportAllCamelGadget::class, 'differ only in case or punctuation');
        $this->boot(ExportAllGadget::class, ExportAllCamelGadget::class);
    }

    #[Test]
    public function aReadOnlyDestructiveActionFailsTheBuild(): void
    {
        $this->expectBuildError(PurgeGadget::class, 'declared both read-only and destructive');
        $this->boot(PurgeGadget::class);
    }

    #[Test]
    public function aReservedExtensionNameFailsTheBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiAction extensions declares "x-oai-tag"');
        $this->boot(ReservedExtensionGadget::class);
    }

    #[Test]
    public function aMultiMethodActionBuildsAndRoutesEveryMethod(): void
    {
        $this->boot(SyncGadget::class, ExportGadget::class);

        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $route = $router->getRouteCollection()->get('jsonapi.guard-gadgets.action.resource.sync');
        self::assertNotNull($route);
        self::assertSame(['GET', 'POST'], $route->getMethods());
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
