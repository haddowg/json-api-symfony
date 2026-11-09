<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class CpInMemoryTestKernel extends CpDoctrineTestKernel
{
    protected function doctrine(): bool
    {
        return false;
    }

    protected function name(): string
    {
        return 'computed-pivot-inmemory';
    }

    protected function configureData(ContainerConfigurator $container, ServicesConfigurator $services): void
    {
        $services->set(CpCrewResource::class);
        $services->set(CpMemberResource::class);
        $services->set('test.cp_fixtures', CpFixtures::class);

        foreach (['members', 'crews'] as $store) {
            $services->set('test.cp_' . $store . '_provider', InMemoryDataProvider::class)
                ->factory([service('test.cp_fixtures'), $store])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
            $services->set('test.cp_' . $store . '_persister', InMemoryDataPersister::class)
                ->factory([service('test.cp_fixtures'), $store . 'Persister'])
                ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
        }
    }
}
