<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\DataProvider\InMemorySnapshotCoordinator;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

/**
 * {@see IdMatrixDoctrineTestKernel} with every row in memory, holding the same value
 * objects a Doctrine hydration gives.
 */
final class IdMatrixInMemoryTestKernel extends IdMatrixDoctrineTestKernel
{
    private const array TYPES = [
        'im-int-owners' => ImIntOwner::class, 'im-str-owners' => ImStrOwner::class, 'im-uuid-owners' => ImUuidOwner::class,
        'im-ulid-owners' => ImUlidOwner::class, 'im-guid-owners' => ImGuidOwner::class, 'im-code-owners' => ImCodeOwner::class,
        'im-enc-owners' => ImEncOwner::class, 'im-prefix-owners' => ImPrefixOwner::class,
        'im-notes' => ImNote::class, 'im-posts' => ImPost::class, 'im-videos' => ImVideo::class,
    ];

    /**
     * @var array<string, InMemoryDataProvider>|null
     */
    private static ?array $providers = null;

    protected function doctrine(): bool
    {
        return false;
    }

    protected function resourcePrefix(): string
    {
        return 'InMemory';
    }

    protected function name(): string
    {
        return 'id-matrix-inmemory';
    }

    /**
     * Seeds every store afresh from {@see IdMatrixSeed}.
     */
    public static function reset(): void
    {
        $seed = IdMatrixSeed::build();
        $coordinator = new InMemorySnapshotCoordinator();
        self::$providers = [];
        foreach (self::TYPES as $type => $class) {
            $rows = [];
            foreach ($seed[$class] as $row) {
                $rows[IdMatrixSeed::wireIdOf($row)] = $row;
            }
            self::$providers[$type] = new InMemoryDataProvider($type, $rows, IdMatrixSeed::wireIdOf(...), coordinator: $coordinator);
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
            static fn(): object => throw new \LogicException('No ' . $type . ' is created here.'),
            static fn(string $relatedType, string $id): ?object => isset(self::TYPES[$relatedType]) ? self::provider($relatedType)->fetchOne($relatedType, $id) : null,
        );
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
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
