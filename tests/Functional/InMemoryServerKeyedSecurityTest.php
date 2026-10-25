<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\BothServersInMemorySecurityTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecuredWidget;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecuredWidgetFactory;

/**
 * {@see ServerKeyedSecurityConformance} on the in-memory provider.
 */
final class InMemoryServerKeyedSecurityTest extends JsonApiFunctionalTestCase
{
    use ServerKeyedSecurityConformance;

    protected static function getKernelClass(): string
    {
        return BothServersInMemorySecurityTestKernel::class;
    }

    protected function afterBoot(): void
    {
        InMemorySecuredWidgetFactory::reset();
        $provider = static::getContainer()->get('test.in_memory_secured_widgets_provider');
        \assert($provider instanceof InMemoryDataProvider);

        $one = new InMemorySecuredWidget(1, 'one');
        $two = new InMemorySecuredWidget(2, 'two');
        $classified = new InMemorySecuredWidget(3, 'classified');
        $four = new InMemorySecuredWidget(4, 'four');
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
