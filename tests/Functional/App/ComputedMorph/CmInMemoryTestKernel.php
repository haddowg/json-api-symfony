<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedMorph;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\HidingProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class CmInMemoryTestKernel extends AbstractCmKernel
{
    protected function dataBundles(): iterable
    {
        return [];
    }

    protected function name(): string
    {
        return 'computed-morph-inmemory';
    }

    protected function scoped(): bool
    {
        return false;
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $services->set(CmFixtures::class);
        $services->alias(CmCompanyLookup::class, CmFixtures::class);

        foreach (['cm-users', 'cm-agencies', 'cm-advertisers', 'cm-publishers'] as $type) {
            $provider = $services->set('test.cm_provider.' . $type, InMemoryDataProvider::class)
                ->factory([service(CmFixtures::class), 'provider'])
                ->args([$type]);

            if ($type === 'cm-agencies' && $this->scoped()) {
                $provider->autoconfigure(false);
                $services->set('test.cm_hiding_provider.' . $type, HidingProvider::class)
                    ->autoconfigure(false)
                    ->args([[$type], service('test.cm_provider.' . $type)])
                    ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
            } else {
                $provider->tag(JsonApiBundle::DATA_PROVIDER_TAG);
            }

            $services->set('test.cm_persister.' . $type, InMemoryDataPersister::class)
                ->factory([service(CmFixtures::class), 'persister'])
                ->args([$type])
                ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
        }
    }
}
