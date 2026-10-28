<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Http\HeaderWidgetFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\ServerDefaultsInMemoryResponseHeadersTestKernel;

/**
 * {@see ServerDefaultResponseHeadersConformance} on the in-memory provider.
 */
final class InMemoryServerDefaultResponseHeadersTest extends JsonApiFunctionalTestCase
{
    use ServerDefaultResponseHeadersConformance;

    protected static function getKernelClass(): string
    {
        return ServerDefaultsInMemoryResponseHeadersTestKernel::class;
    }

    protected function afterBoot(): void
    {
        HeaderWidgetFactory::reset();
    }
}
