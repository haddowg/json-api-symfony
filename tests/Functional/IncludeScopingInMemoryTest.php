<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\IncludeScopingInMemoryTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\ScopingAgency;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\ScopingFixtures;

/**
 * {@see IncludeScopingConformanceTestCase} on the in-memory provider, with an
 * application provider for the related types hiding the rows.
 */
final class IncludeScopingInMemoryTest extends IncludeScopingConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return IncludeScopingInMemoryTestKernel::class;
    }

    protected function hiddenAgencyName(): string
    {
        $agency = ScopingFixtures::inMemoryProvider('agencies')->fetchOne('agencies', '2');
        \assert($agency instanceof ScopingAgency);

        return $agency->name;
    }
}
