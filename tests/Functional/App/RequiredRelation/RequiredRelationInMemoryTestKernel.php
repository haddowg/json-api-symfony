<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RequiredRelation;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class RequiredRelationInMemoryTestKernel extends RequiredRelationDoctrineTestKernel
{
    protected function doctrine(): bool
    {
        return false;
    }

    protected function name(): string
    {
        return 'required-relation-inmemory';
    }

    protected function configureData(ContainerConfigurator $container, ServicesConfigurator $services): void
    {
        $services->set(TaskResource::class);
        $services->set(MemberResource::class);
        $services->set('test.rr_fixtures', Fixtures::class);

        foreach (['members', 'tasks'] as $store) {
            $services->set('test.rr_' . $store . '_provider', InMemoryDataProvider::class)
                ->factory([service('test.rr_fixtures'), $store])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
            $services->set('test.rr_' . $store . '_persister', InMemoryDataPersister::class)
                ->factory([service('test.rr_fixtures'), $store . 'Persister'])
                ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
        }
    }
}
