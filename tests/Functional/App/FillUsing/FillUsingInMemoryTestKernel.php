<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FillUsing;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\HidingProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class FillUsingInMemoryTestKernel extends AbstractFillUsingKernel
{
    protected function dataBundles(): iterable
    {
        return [];
    }

    protected function name(): string
    {
        return 'fill-using-inmemory';
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $services->set(FillUsingFixtures::class);

        $services->set('test.fill_provider.fill-files', InMemoryDataProvider::class)
            ->factory([service(FillUsingFixtures::class), 'provider'])
            ->args(['fill-files'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        foreach (['fill-agencies', 'fill-members'] as $type) {
            $services->set('test.fill_inner_provider.' . $type, InMemoryDataProvider::class)
                ->autoconfigure(false)
                ->factory([service(FillUsingFixtures::class), 'provider'])
                ->args([$type]);
            $services->set('test.fill_provider.' . $type, HidingProvider::class)
                ->autoconfigure(false)
                ->args([[$type], service('test.fill_inner_provider.' . $type)])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        }

        foreach (['fill-agencies', 'fill-members', 'fill-files'] as $type) {
            $services->set('test.fill_persister.' . $type, InMemoryDataPersister::class)
                ->factory([service(FillUsingFixtures::class), 'persister'])
                ->args([$type])
                ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
        }
    }
}
