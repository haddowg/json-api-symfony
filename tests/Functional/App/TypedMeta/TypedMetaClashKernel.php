<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedMeta;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

/**
 * Adds `tm-clashes`, whose collection declares a member core writes, so warm-up fails.
 */
final class TypedMetaClashKernel extends TypedMetaInMemoryTestKernel
{
    protected function name(): string
    {
        return 'typed-meta-clash';
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        parent::configureData($container, $services);

        $services->set(TmClashResource::class);
        $services->set('test.provider.tm-clashes', InMemoryDataProvider::class)
            ->args(['tm-clashes', []])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
    }
}
