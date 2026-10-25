<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\SecurityTestKernel;

/**
 * {@see SecurityTestKernel} with its resources on a named server.
 */
final class NamedServerSecurityTestKernel extends SecurityTestKernel
{
    use ServesOnNamedServer;
}
