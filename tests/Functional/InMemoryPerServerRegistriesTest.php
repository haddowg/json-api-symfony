<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\BothServersInMemoryRegistriesTestKernel;

/**
 * {@see ServerKeyedRegistriesConformance} and {@see ServerKeyedIdPatternConformance} on
 * the in-memory kernel. The in-memory layer stores rows by their wire id and decodes
 * none, so the id decoding is witnessed on the Doctrine kernel only.
 */
final class InMemoryPerServerRegistriesTest extends JsonApiFunctionalTestCase
{
    use ServerKeyedIdPatternConformance;
    use ServerKeyedRegistriesConformance;

    protected static function getKernelClass(): string
    {
        return BothServersInMemoryRegistriesTestKernel::class;
    }
}
