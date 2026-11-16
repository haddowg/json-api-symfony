<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Singleton;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\DataProvider\InMemorySnapshotCoordinator;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * {@see SingletonDoctrineTestKernel} in memory, with {@see OwnInboxProvider} as the
 * `inboxes` access scope.
 */
final class SingletonInMemoryTestKernel extends SingletonDoctrineTestKernel
{
    private static ?InMemoryDataProvider $inboxes = null;

    private static ?InMemoryDataProvider $items = null;

    protected function doctrine(): bool
    {
        return false;
    }

    protected function name(): string
    {
        return 'singleton-inmemory';
    }

    /**
     * Seeds both stores afresh: inbox 1 (ada) holds items 1 and 2 and pins item 2, inbox 2
     * (grace) holds item 3.
     */
    public static function reset(): void
    {
        $coordinator = new InMemorySnapshotCoordinator();
        $ada = new Inbox(1, 'ada', 'Ada', 3);
        $grace = new Inbox(2, 'grace', 'Grace', 1);
        $first = new InboxItem(1, 'Hello', $ada);
        $second = new InboxItem(2, 'Again', $ada);
        $third = new InboxItem(3, 'Private', $grace);
        $ada->pinned = $second;

        $identify = static fn(object $item): string => $item instanceof Inbox || $item instanceof InboxItem ? (string) $item->id : '';
        self::$inboxes = new InMemoryDataProvider('inboxes', ['1' => $ada, '2' => $grace], $identify, coordinator: $coordinator);
        self::$items = new InMemoryDataProvider('inbox-items', ['1' => $first, '2' => $second, '3' => $third], $identify, coordinator: $coordinator);
    }

    public static function inboxes(): InMemoryDataProvider
    {
        if (self::$inboxes === null) {
            self::reset();
        }
        \assert(self::$inboxes !== null);

        return self::$inboxes;
    }

    public static function items(): InMemoryDataProvider
    {
        if (self::$items === null) {
            self::reset();
        }
        \assert(self::$items !== null);

        return self::$items;
    }

    public static function inboxPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister(
            'inboxes',
            $provider->store(),
            static fn(): Inbox => new Inbox(),
            static fn(string $type, string $id): ?object => $type === 'inbox-items' ? self::items()->fetchOne($type, $id) : null,
        );
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $services->set(InMemoryInboxResource::class);
        $services->set(InMemoryInboxItemResource::class);

        // The store behind the scope; not a provider itself.
        $services->set('test.inboxes_store', InMemoryDataProvider::class)
            ->factory([self::class, 'inboxes'])
            ->autoconfigure(false);
        $services->set(OwnInboxProvider::class)
            ->arg('$inner', service('test.inboxes_store'));
        $services->set('test.inboxes_persister', InMemoryDataPersister::class)
            ->factory([self::class, 'inboxPersister'])
            ->args([service('test.inboxes_store')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
        $services->set('test.inbox_items_provider', InMemoryDataProvider::class)
            ->factory([self::class, 'items'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
    }
}
