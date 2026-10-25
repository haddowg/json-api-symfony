<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\NamedServerSecurityTestKernel;

/**
 * {@see DoctrineRelationAuthorizerTest} with its resources on a named server.
 */
final class DoctrineNamedServerRelationAuthorizerTest extends DoctrineRelationAuthorizerTest
{
    use RequestsNamedServer;

    protected static function getKernelClass(): string
    {
        return NamedServerSecurityTestKernel::class;
    }
}
