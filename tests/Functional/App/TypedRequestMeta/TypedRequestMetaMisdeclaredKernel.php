<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

/**
 * Adds one misdeclaration, chosen by the environment, so warm-up fails on it.
 */
final class TypedRequestMetaMisdeclaredKernel extends TypedRequestMetaInMemoryTestKernel
{
    public const array MISDECLARATIONS = [
        'getmeta' => TrGetMetaResource::class,
        'twice' => TrTwiceResource::class,
        'entries' => TrTwoEntriesResource::class,
        'raw' => RawUploadNote::class,
    ];

    protected function name(): string
    {
        return 'typed-request-meta-misdeclared';
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        parent::configureData($container, $services);

        $class = self::MISDECLARATIONS[$this->environment] ?? null;
        if ($class === null) {
            return;
        }
        $services->set($class);
        if ($class !== RawUploadNote::class) {
            $type = $class::$type;
            $services->set('test.provider.' . $type, InMemoryDataProvider::class)
                ->args([$type, []])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        }
    }
}
