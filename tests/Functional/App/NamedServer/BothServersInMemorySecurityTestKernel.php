<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecurityTestKernel;

/**
 * {@see InMemorySecurityTestKernel} with its resources on a named server and another
 * `securedWidgets` on the default server.
 */
final class BothServersInMemorySecurityTestKernel extends InMemorySecurityTestKernel
{
    use ServesOnBothServers;

    protected function ownServerResources(): array
    {
        return [InMemoryDefaultServerSecuredWidgetResource::class];
    }
}
