<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecuredWidget;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecuredWidgetFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecurityTestKernel;

/**
 * {@see RelatedRowReadGateConformance} on the in-memory provider.
 */
final class InMemoryRelatedRowReadGateTest extends JsonApiFunctionalTestCase
{
    use RelatedRowReadGateConformance;

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
        $two = new InMemorySecuredWidget(2, 'two');
        $classified = new InMemorySecuredWidget(3, 'classified');
        $four = new InMemorySecuredWidget(4, 'four', $one);
        $one->partner = $classified;
        $one->partners = [$two, $classified];

        $store = $provider->store();
        foreach ($store->all() as $item) {
            $store->remove($item);
        }
        foreach ([$one, $two, $classified, $four] as $widget) {
            $store->save($widget);
        }
    }
}
