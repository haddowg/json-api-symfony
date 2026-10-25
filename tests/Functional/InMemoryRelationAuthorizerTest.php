<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecuredWidget;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecuredWidgetFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecurityTestKernel;

/**
 * The relation authorizer on the in-memory provider: widgets 1 and 2 are each other's
 * partner, so the admin-only `adminPartner` has data to withhold.
 */
class InMemoryRelationAuthorizerTest extends JsonApiFunctionalTestCase
{
    use RelationAuthorizerConformance;

    protected static function getKernelClass(): string
    {
        return InMemorySecurityTestKernel::class;
    }

    protected function afterBoot(): void
    {
        InMemorySecuredWidgetFactory::reset();
        // The container's own provider, which may have been built before the reset
        // above, so its items are replaced.
        $provider = static::getContainer()->get('test.in_memory_secured_widgets_provider');
        \assert($provider instanceof InMemoryDataProvider);
        $one = new InMemorySecuredWidget(1, 'one');
        $two = new InMemorySecuredWidget(2, 'two', $one);
        $one->partner = $two;
        $store = $provider->store();
        foreach ($store->all() as $item) {
            $store->remove($item);
        }
        $store->save($one);
        $store->save($two);
    }
}
