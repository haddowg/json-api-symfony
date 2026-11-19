<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotNumbers;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class PnInMemoryTestKernel extends PnDoctrineTestKernel
{
    protected function doctrine(): bool
    {
        return false;
    }

    protected function name(): string
    {
        return 'pivot-numbers-inmemory';
    }

    protected function configureData(ContainerConfigurator $container, ServicesConfigurator $services): void
    {
        $services->set(PnBoardResource::class);
        $services->set(PnCardResource::class);
        $services->set(PnFixtures::class)->public();

        foreach (['pn-boards', 'pn-cards'] as $type) {
            $services->set('test.pn_provider.' . $type, InMemoryDataProvider::class)
                ->factory([service(PnFixtures::class), 'provider'])
                ->args([$type])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
            $services->set('test.pn_persister.' . $type, InMemoryDataPersister::class)
                ->factory([service(PnFixtures::class), 'persister'])
                ->args([$type])
                ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
        }
    }
}
