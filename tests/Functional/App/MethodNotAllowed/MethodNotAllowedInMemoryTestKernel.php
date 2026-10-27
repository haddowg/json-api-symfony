<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MethodNotAllowed;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class MethodNotAllowedInMemoryTestKernel extends MethodNotAllowedDoctrineTestKernel
{
    protected function doctrine(): bool
    {
        return false;
    }

    protected function name(): string
    {
        return 'method-not-allowed-inmemory';
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $services->set(InMemoryAgencyResource::class);
        $services->set(InMemoryAdvertiserResource::class);

        $services->set('test.agencies_provider', InMemoryDataProvider::class)
            ->factory([self::class, 'agencies'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.advertisers_provider', InMemoryDataProvider::class)
            ->factory([self::class, 'advertisers'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.advertisers_persister', InMemoryDataPersister::class)
            ->factory([self::class, 'advertisersPersister'])
            ->args([service('test.advertisers_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    public static function agencies(): InMemoryDataProvider
    {
        $agency = new Agency();
        $agency->id = 1;
        $agency->name = 'Seeded agency';

        return self::provider('agencies', $agency);
    }

    public static function advertisers(): InMemoryDataProvider
    {
        $advertiser = new Advertiser();
        $advertiser->id = 1;
        $advertiser->name = 'Seeded advertiser';

        return self::provider('advertisers', $advertiser);
    }

    public static function advertisersPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('advertisers', $provider->store(), static fn(): Advertiser => new Advertiser());
    }

    private static function provider(string $type, Agency|Advertiser $seed): InMemoryDataProvider
    {
        return new InMemoryDataProvider(
            $type,
            ['1' => $seed],
            static fn(object $item): string => ($item instanceof Agency || $item instanceof Advertiser) && $item->id !== null ? (string) $item->id : '',
            static function (object $item, string $id): void {
                \assert($item instanceof Agency || $item instanceof Advertiser);
                $item->id = (int) $id;
            },
        );
    }
}
