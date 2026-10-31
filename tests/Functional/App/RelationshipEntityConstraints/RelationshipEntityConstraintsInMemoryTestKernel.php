<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationshipEntityConstraints;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class RelationshipEntityConstraintsInMemoryTestKernel extends RelationshipEntityConstraintsDoctrineTestKernel
{
    protected function doctrine(): bool
    {
        return false;
    }

    protected function name(): string
    {
        return 'relationship-entity-constraints-inmemory';
    }

    protected function configureData(ContainerConfigurator $container, ServicesConfigurator $services): void
    {
        $services->set(IntegrationUserResource::class);
        $services->set(CompanyResource::class);
        $services->set(TeamResource::class);
        $services->set('test.rec_fixtures', Fixtures::class);

        foreach (['companies', 'users', 'teams'] as $store) {
            $services->set('test.rec_' . $store . '_provider', InMemoryDataProvider::class)
                ->factory([service('test.rec_fixtures'), $store])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        }
        foreach (['companies', 'users', 'teams'] as $store) {
            $services->set('test.rec_' . $store . '_persister', InMemoryDataPersister::class)
                ->factory([service('test.rec_fixtures'), $store . 'Persister'])
                ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
        }
    }
}
