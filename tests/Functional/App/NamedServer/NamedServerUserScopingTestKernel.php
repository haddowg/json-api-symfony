<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\UserScopingTestKernel;

/**
 * {@see UserScopingTestKernel} with its resources on a named server.
 */
final class NamedServerUserScopingTestKernel extends UserScopingTestKernel
{
    use ServesOnNamedServer;
}
