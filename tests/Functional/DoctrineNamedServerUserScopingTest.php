<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\NamedServerUserScopingTestKernel;

/**
 * {@see DoctrineUserScopingTest} with its resources on a named server.
 */
final class DoctrineNamedServerUserScopingTest extends DoctrineUserScopingTest
{
    use RequestsNamedServer;

    protected static function getKernelClass(): string
    {
        return NamedServerUserScopingTestKernel::class;
    }
}
