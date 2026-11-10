<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder\OrderInMemoryTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder\OrderShelfResource;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder\PivotOrderShelfResource;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder\UnknownOrderShelfResource;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder\WritablePositionShelfResource;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A default read order a relation can't apply fails warm-up rather than every read.
 */
final class RelationOrderBuildTest extends KernelTestCase
{
    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected static function getKernelClass(): string
    {
        return OrderInMemoryTestKernel::class;
    }

    protected function setUp(): void
    {
        $this->errorHandler = \set_error_handler(null);
        \restore_error_handler();
        $this->exceptionHandler = \set_exception_handler(null);
        \restore_exception_handler();
    }

    #[Test]
    public function aDefaultSortOnAKeyTheRelationDoesNotAcceptFailsWarmUp(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Relation "booksByPages" on resource type "shelves" declares a defaultSort() on "pages", which is not a sort the relation accepts.');

        $this->bootWith(UnknownOrderShelfResource::class);
    }

    #[Test]
    public function aRelationPositionedByAWritableFieldFailsWarmUp(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Relation "booksByAge" on resource type "shelves" is positioned by "year" on type "books", but it is writable');

        $this->bootWith(WritablePositionShelfResource::class);
    }

    #[Test]
    public function aPivotDefaultSortOnAProviderWithoutPivotRowsFailsWarmUp(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Relation "picks" on resource type "shelves" is ordered by the pivot field "rank", but the data provider of "books" does not read the relation through a pivot query');

        $this->bootWith(PivotOrderShelfResource::class);
    }

    /**
     * @param class-string<OrderShelfResource> $shelves
     */
    private function bootWith(string $shelves): void
    {
        static::ensureKernelShutdown();
        OrderInMemoryTestKernel::$shelves = $shelves;
        static::bootKernel(['debug' => false]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
        OrderInMemoryTestKernel::$shelves = OrderShelfResource::class;

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
