<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SelfThrough;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class StInMemoryTestKernel extends StDoctrineTestKernel
{
    protected function doctrine(): bool
    {
        return false;
    }

    protected function name(): string
    {
        return 'self-through-inmemory';
    }

    protected function configureData(ContainerConfigurator $container, ServicesConfigurator $services): void
    {
        $services->set(InMemoryStOwnerResource::class);
        $services->set(InMemoryStUuidOwnerResource::class);
        $services->set(InMemoryStGuidOwnerResource::class);
        $services->set(StFixtures::class)->public();

        foreach (\array_keys(StFixtures::TYPES) as $type) {
            $services->set('test.st_provider.' . $type, InMemoryDataProvider::class)
                ->factory([service(StFixtures::class), 'provider'])
                ->args([$type])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
            $services->set('test.st_persister.' . $type, InMemoryDataPersister::class)
                ->factory([service(StFixtures::class), 'persister'])
                ->args([$type])
                ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
        }
    }
}
