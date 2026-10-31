<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\HidingProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class MtmInMemoryTestKernel extends AbstractMtmKernel
{
    protected function dataBundles(): iterable
    {
        return [];
    }

    protected function name(): string
    {
        return 'identified-morph-many-inmemory';
    }

    protected function scoped(): bool
    {
        return false;
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $services->set(MtmFixtures::class);

        foreach (['users', 'agencies', 'advertisers', 'publishers'] as $type) {
            $provider = $services->set('test.mtm_provider.' . $type, InMemoryDataProvider::class)
                ->factory([service(MtmFixtures::class), 'provider'])
                ->args([$type]);

            if ($type === 'agencies' && $this->scoped()) {
                $provider->autoconfigure(false);
                $services->set('test.mtm_hiding_provider.' . $type, HidingProvider::class)
                    ->autoconfigure(false)
                    ->args([[$type], service('test.mtm_provider.' . $type)])
                    ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
            } else {
                $provider->tag(JsonApiBundle::DATA_PROVIDER_TAG);
            }

            $services->set('test.mtm_persister.' . $type, InMemoryDataPersister::class)
                ->factory([service(MtmFixtures::class), 'persister'])
                ->args([$type])
                ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
        }
    }
}
