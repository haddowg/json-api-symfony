<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\RelationFilterDefaults\AbstractFdKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationFilterDefaults\FdAlbumResource;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationFilterDefaults\FdDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationFilterDefaults\FdInMemoryTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationFilterDefaults\UnknownDefaultAlbumResource;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Dropping the default of a key the related type doesn't default fails warm-up rather
 * than every read of the relation.
 */
final class RelationFilterDefaultsBuildTest extends KernelTestCase
{
    /**
     * @var class-string<AbstractFdKernel>
     */
    private static string $kernelClass = FdInMemoryTestKernel::class;

    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected static function getKernelClass(): string
    {
        return self::$kernelClass;
    }

    protected function setUp(): void
    {
        $this->errorHandler = \set_error_handler(null);
        \restore_error_handler();
        $this->exceptionHandler = \set_exception_handler(null);
        \restore_exception_handler();
    }

    #[Test]
    public function droppingTheDefaultOfAKeyWithNoDefaultFailsInMemoryWarmUp(): void
    {
        $this->expectUnknownKeyFailure();
        $this->bootWith(FdInMemoryTestKernel::class);
    }

    #[Test]
    public function droppingTheDefaultOfAKeyWithNoDefaultFailsDoctrineWarmUp(): void
    {
        $this->expectUnknownKeyFailure();
        $this->bootWith(FdDoctrineTestKernel::class);
    }

    private function expectUnknownKeyFailure(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Relation "titledTracks" on resource type "albums" declares withoutFilterDefaults() on "title", which is not a filter declaring a default on its related type.');
    }

    /**
     * @param class-string<AbstractFdKernel> $kernel
     */
    private function bootWith(string $kernel): void
    {
        static::ensureKernelShutdown();
        self::$kernelClass = $kernel;
        AbstractFdKernel::$albums = UnknownDefaultAlbumResource::class;
        static::bootKernel(['debug' => false]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
        self::$kernelClass = FdInMemoryTestKernel::class;
        AbstractFdKernel::$albums = FdAlbumResource::class;

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
}
