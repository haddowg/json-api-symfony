<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecuredWidget;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecuredWidgetFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecurityTestKernel;

/**
 * The relation authorizer on the in-memory provider: widgets 1 and 2 are each other's
 * partner, so the admin-only `adminPartner` has data to withhold.
 */
final class InMemoryRelationAuthorizerTest extends JsonApiFunctionalTestCase
{
    use RelationAuthorizerConformance;

    protected static function getKernelClass(): string
    {
        return InMemorySecurityTestKernel::class;
    }

    protected function afterBoot(): void
    {
        InMemorySecuredWidgetFactory::reset();
        $store = InMemorySecuredWidgetFactory::createProvider()->store();
        $one = $store->find('1');
        \assert($one instanceof InMemorySecuredWidget);
        $two = new InMemorySecuredWidget(2, 'two', $one);
        $one->partner = $two;
        $store->save($two);
    }
}
