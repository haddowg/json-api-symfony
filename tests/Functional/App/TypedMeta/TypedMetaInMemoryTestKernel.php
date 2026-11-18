<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedMeta;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class TypedMetaInMemoryTestKernel extends TypedMetaDoctrineTestKernel
{
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
        return 'typed-meta-inmemory';
    }

    /**
     * Seeds every store afresh from {@see TypedMetaSeed}.
     */
    public static function reset(): void
    {
        $seed = TypedMetaSeed::build();
        $identify = static fn(object $row): string => \property_exists($row, 'id') && \is_int($row->id) ? (string) $row->id : '';

        self::$providers = [];
        foreach (['tm-boards' => 'boards', 'tm-cards' => 'cards'] as $type => $key) {
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

    public static function cardPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister(
            'tm-cards',
            $provider->store(),
            static fn(): TmCard => new TmCard(),
            static fn(string $type, string $id): ?object => self::provider($type)->fetchOne($type, $id),
        );
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $services->set(InMemoryTmCardResource::class);
        $services->set(InMemoryTmBoardResource::class);

        foreach (['tm-boards', 'tm-cards'] as $type) {
            $services->set('test.provider.' . $type, InMemoryDataProvider::class)
                ->factory([self::class, 'provider'])
                ->args([$type])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        }

        $services->set('test.persister.tm-cards', InMemoryDataPersister::class)
            ->factory([self::class, 'cardPersister'])
            ->args([service('test.provider.tm-cards')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }
}
