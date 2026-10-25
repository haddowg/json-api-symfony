<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecurityTestKernel;

/**
 * {@see InMemorySecurityTestKernel} with its resources on a named server.
 */
final class NamedServerInMemorySecurityTestKernel extends InMemorySecurityTestKernel
{
    use ServesOnNamedServer;
}
