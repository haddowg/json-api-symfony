<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class TypedRequestMetaInMemoryTestKernel extends TypedRequestMetaDoctrineTestKernel
{
    private const array TYPES = ['tr-notes' => 'notes', 'tr-items' => 'items', 'tr-plains' => 'plains', 'tr-stamps' => 'plains'];

    /**
     * @var array<string, InMemoryDataProvider>|null
     */
    private static ?array $providers = null;

    protected function doctrine(): bool
    {
        return false;
    }

    protected function name(): string
    {
        return 'typed-request-meta-inmemory';
    }

    /**
     * Seeds every store afresh from {@see TypedRequestMetaSeed}.
     */
    public static function reset(): void
    {
        $seed = TypedRequestMetaSeed::build();
        $identify = static fn(object $row): string => \property_exists($row, 'id') && \is_int($row->id) ? (string) $row->id : '';

        self::$providers = [];
        foreach (self::TYPES as $type => $key) {
            $rows = [];
            foreach ($seed[$key] as $row) {
                $rows[(string) $row->id] = $row;
            }
            self::$providers[$type] = new InMemoryDataProvider($type, $rows, $identify);
        }
    }

    public static function provider(string $type): InMemoryDataProvider
    {
        if (self::$providers === null) {
            self::reset();
        }
        \assert(self::$providers !== null);

        return self::$providers[$type];
    }

    public static function persister(string $type, InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister(
            $type,
            $provider->store(),
            static fn(): object => match ($type) {
                'tr-notes' => new TrNote(),
                'tr-items' => new TrItem(),
                default => new TrPlain(),
            },
            static fn(string $related, string $id): ?object => self::provider($related)->fetchOne($related, $id),
        );
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $services->set(InMemoryTrNoteResource::class);
        $services->set(InMemoryTrItemResource::class);
        $services->set(InMemoryTrPlainResource::class);
        $services->set(InMemoryTrStampResource::class);

        foreach (\array_keys(self::TYPES) as $type) {
            $services->set('test.provider.' . $type, InMemoryDataProvider::class)
                ->factory([self::class, 'provider'])
                ->args([$type])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
            $services->set('test.persister.' . $type, InMemoryDataPersister::class)
                ->factory([self::class, 'persister'])
                ->args([$type, service('test.provider.' . $type)])
                ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
        }
    }
}
