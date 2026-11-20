<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\DataProvider\InMemorySnapshotCoordinator;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

/**
 * {@see ForeignKeyDoctrineTestKernel} with the related rows in memory.
 */
final class ForeignKeyInMemoryTestKernel extends ForeignKeyDoctrineTestKernel
{
    private const array TYPES = [
        'fk-items' => 'items',
        'fk-notes' => 'notes',
        'fk-profiles' => 'profiles',
        'fk-secrets' => 'secrets',
        'fk-teams' => 'teams',
        'fk-members' => 'members',
    ];

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
        return 'foreign-key-inmemory';
    }

    /**
     * Seeds every store afresh from {@see ForeignKeySeed}.
     */
    public static function reset(): void
    {
        $seed = ForeignKeySeed::build();
        $identify = static fn(object $row): string => \property_exists($row, 'id') && \is_int($row->id) ? (string) $row->id : '';
        $coordinator = new InMemorySnapshotCoordinator();

        self::$providers = [];
        foreach (self::TYPES as $type => $key) {
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

    public static function persister(string $type): InMemoryDataPersister
    {
        return new InMemoryDataPersister(
            $type,
            self::provider($type)->store(),
            static fn(): object => $type === 'fk-teams' ? new FkTeam() : throw new \LogicException('No ' . $type . ' is created here.'),
            static fn(string $relatedType, string $id): ?object => isset(self::TYPES[$relatedType]) ? self::provider($relatedType)->fetchOne($relatedType, $id) : null,
        );
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $services->set(InMemoryFkItemResource::class);
        $services->set(InMemoryFkNoteResource::class);
        $services->set(InMemoryFkProfileResource::class);
        $services->set(InMemoryFkSecretResource::class);
        $services->set(InMemoryFkTeamResource::class);
        $services->set(InMemoryFkMemberResource::class);

        foreach (\array_keys(self::TYPES) as $type) {
            $services->set('test.provider.' . $type, InMemoryDataProvider::class)
                ->factory([self::class, 'provider'])
                ->args([$type])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
            $services->set('test.persister.' . $type, InMemoryDataPersister::class)
                ->factory([self::class, 'persister'])
                ->args([$type])
                ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
        }
    }
}
