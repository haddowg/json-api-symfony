<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationFilterDefaults;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class FdInMemoryTestKernel extends AbstractFdKernel
{
    protected function dataBundles(): iterable
    {
        return [];
    }

    protected function baseName(): string
    {
        return 'relation-filter-defaults-inmemory';
    }

    protected function resourceTag(string $type): array
    {
        return ['operations' => 'FetchCollection,FetchOne'];
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $services->set(FdFixtures::class);

        foreach (['albums', 'tracks'] as $type) {
            $services->set('test.fd_provider.' . $type, InMemoryDataProvider::class)
                ->factory([service(FdFixtures::class), 'provider'])
                ->args([$type])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        }
    }
}
