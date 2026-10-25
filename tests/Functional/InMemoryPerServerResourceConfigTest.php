<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Http\HeaderWidgetFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\BothServersInMemoryResponseHeadersTestKernel;

/**
 * {@see ServerKeyedResponseHeadersConformance} and {@see ServerKeyedOpenApiTagsConformance} on the in-memory provider.
 */
final class InMemoryPerServerResourceConfigTest extends JsonApiFunctionalTestCase
{
    use ServerKeyedOpenApiTagsConformance;
    use ServerKeyedResponseHeadersConformance;

    protected static function getKernelClass(): string
    {
        return BothServersInMemoryResponseHeadersTestKernel::class;
    }

    protected function afterBoot(): void
    {
        HeaderWidgetFactory::reset();
    }
}
