<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\SecurityTestKernel;

/**
 * {@see SecurityTestKernel} with its resources on a named server and another
 * `securedWidgets` on the default server.
 */
final class BothServersSecurityTestKernel extends SecurityTestKernel
{
    use ServesOnBothServers;

    protected function defaultServerResource(): string
    {
        return DefaultServerSecuredWidgetResource::class;
    }
}
