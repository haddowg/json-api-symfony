<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SelfThrough;

use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

/**
 * Also serves {@see StLooseOwnerResource}, whose relation can't tell the sides of a seat
 * apart, so warming the cache fails.
 */
final class StAmbiguousTestKernel extends StDoctrineTestKernel
{
    protected function name(): string
    {
        return 'self-through-ambiguous';
    }

    protected function configureData(ContainerConfigurator $container, ServicesConfigurator $services): void
    {
        parent::configureData($container, $services);
        $services->set(StLooseOwnerResource::class)->tag(JsonApiBundle::RESOURCE_TAG, ['entity' => StOwner::class]);
    }
}
