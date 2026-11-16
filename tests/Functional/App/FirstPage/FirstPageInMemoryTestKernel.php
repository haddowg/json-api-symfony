<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FirstPage;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\DataProvider\InMemorySnapshotCoordinator;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * {@see FirstPageDoctrineTestKernel} in memory.
 */
final class FirstPageInMemoryTestKernel extends FirstPageDoctrineTestKernel
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
        return 'first-page-inmemory';
    }

    /**
     * Seeds every store afresh from {@see FirstPageSeed}.
     */
    public static function reset(): void
    {
        $seed = FirstPageSeed::build();
        $coordinator = new InMemorySnapshotCoordinator();
        $identify = static fn(object $row): string => \property_exists($row, 'id') && \is_int($row->id) ? (string) $row->id : '';

        self::$providers = [];
        foreach (['fp-desks' => 'desks', 'fp-boards' => 'boards', 'fp-cards' => 'cards', 'fp-comments' => 'comments', 'fp-labels' => 'labels'] as $type => $key) {
            $rows = [];
            foreach ($seed[$key] as $row) {
                $rows[(string) $row->id] = $row;
            }
            self::$providers[$type] = new InMemoryDataProvider($type, $rows, $identify, coordinator: $coordinator);
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

    public static function boardPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister(
            'fp-boards',
            $provider->store(),
            static fn(): FpBoard => new FpBoard(),
            static fn(string $type, string $id): ?object => self::provider($type)->fetchOne($type, $id),
        );
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $services->set(InMemoryFpDeskResource::class);
        $services->set(InMemoryFpBoardResource::class);
        $services->set(InMemoryFpCardResource::class);
        $services->set(InMemoryFpCommentResource::class);
        $services->set(InMemoryFpLabelResource::class);

        foreach (['fp-desks', 'fp-boards', 'fp-cards', 'fp-comments', 'fp-labels'] as $type) {
            $services->set('test.provider.' . $type, InMemoryDataProvider::class)
                ->factory([self::class, 'provider'])
                ->args([$type])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        }

        $services->set('test.persister.fp-boards', InMemoryDataPersister::class)
            ->factory([self::class, 'boardPersister'])
            ->args([service('test.provider.fp-boards')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }
}
