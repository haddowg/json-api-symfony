<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\NamedServerInMemorySecurityTestKernel;

/**
 * {@see InMemoryRelatedRowReadGateTest} with its resources on a named server.
 */
final class InMemoryNamedServerRelatedRowReadGateTest extends InMemoryRelatedRowReadGateTest
{
    use RequestsNamedServer;

    protected static function getKernelClass(): string
    {
        return NamedServerInMemorySecurityTestKernel::class;
    }
}
