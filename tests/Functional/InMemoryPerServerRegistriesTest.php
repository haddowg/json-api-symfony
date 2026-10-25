<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\BothServersInMemoryRegistriesTestKernel;

/**
 * {@see ServerKeyedRegistriesConformance} on the in-memory kernel.
 */
final class InMemoryPerServerRegistriesTest extends JsonApiFunctionalTestCase
{
    use ServerKeyedRegistriesConformance;

    protected static function getKernelClass(): string
    {
        return BothServersInMemoryRegistriesTestKernel::class;
    }
}
