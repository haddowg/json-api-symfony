<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\RelatedQuery\RqFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\RelatedQuery\RqInMemoryTestKernel;

final class InMemoryRelatedQueryOperationsTest extends RelatedQueryOperationsConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return RqInMemoryTestKernel::class;
    }

    protected function afterBoot(): void
    {
        RqFixtures::reset();
    }
}
