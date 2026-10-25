<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecuredWidgetFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecurityTestKernel;

/**
 * {@see UserScopingConformance} on the in-memory kernel, where widget 1 holds the memos.
 */
class InMemoryUserScopingTest extends JsonApiFunctionalTestCase
{
    use UserScopingConformance;

    protected static function getKernelClass(): string
    {
        return InMemorySecurityTestKernel::class;
    }

    protected function afterBoot(): void
    {
        InMemorySecuredWidgetFactory::reset();
    }

    protected function memoParent(): string
    {
        return '/securedWidgets/1';
    }
}
