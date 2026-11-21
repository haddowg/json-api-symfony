<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\PivotNumbers\PnBoard;
use haddowg\JsonApiBundle\Tests\Functional\App\PivotNumbers\PnFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\PivotNumbers\PnInMemoryTestKernel;

final class InMemoryPivotNumbersTest extends PivotNumbersConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return PnInMemoryTestKernel::class;
    }

    protected function board(string $id): PnBoard
    {
        $fixtures = static::getContainer()->get(PnFixtures::class);
        self::assertInstanceOf(PnFixtures::class, $fixtures);
        $board = $fixtures->provider('pn-boards')->store()->find($id);
        self::assertInstanceOf(PnBoard::class, $board);

        return $board;
    }
}
