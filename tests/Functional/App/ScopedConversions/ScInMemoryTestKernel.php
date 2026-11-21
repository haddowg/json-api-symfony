<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ScopedConversions;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class ScInMemoryTestKernel extends ScDoctrineTestKernel
{
    protected function doctrine(): bool
    {
        return false;
    }

    protected function name(): string
    {
        return 'scoped-conversions-inmemory';
    }

    protected function configureData(ContainerConfigurator $container, ServicesConfigurator $services): void
    {
        $services->set(ScJournalResource::class);
        $services->set(ScFixtures::class)->public();
        $services->set('test.sc_provider', InMemoryDataProvider::class)
            ->factory([service(ScFixtures::class), 'provider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.sc_persister', InMemoryDataPersister::class)
            ->factory([service(ScFixtures::class), 'persister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }
}
