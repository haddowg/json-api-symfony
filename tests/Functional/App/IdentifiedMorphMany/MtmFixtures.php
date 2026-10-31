<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\DataProvider\InMemorySnapshotCoordinator;

/**
 * The seed both kernels share, and the Laravel adapter's twin of this suite. User 1 holds
 * six companies across the three types, one of them the hidden agency 2; user 2 holds
 * one; user 3 holds two and is the one the writes change; user 4 holds none.
 */
final class MtmFixtures
{
    public const array USERS = [
        1 => ['Ada', ['agencies:1', 'advertisers:1', 'publishers:1', 'agencies:2', 'advertisers:2', 'agencies:3']],
        2 => ['Bob', ['advertisers:2']],
        3 => ['Cy', ['agencies:1', 'publishers:1']],
        4 => ['Di', []],
    ];

    /**
     * @var array<string, InMemoryDataProvider>|null
     */
    private ?array $providers = null;

    /**
     * @return array{agencies: list<MtmAgency>, advertisers: list<MtmAdvertiser>, publishers: list<MtmPublisher>, users: list<MtmUser>}
     */
    public static function graph(): array
    {
        $users = [];
        foreach (self::USERS as $id => [$name, $companies]) {
            $user = new MtmUser();
            $user->id = $id;
            $user->name = $name;
            foreach ($companies as $company) {
                [$type, $companyId] = \explode(':', $company);
                $user->addCompany($type, (int) $companyId);
            }
            $users[] = $user;
        }

        return [
            'agencies' => [new MtmAgency(1, 'Agency One', 'AG1'), new MtmAgency(2, 'Hidden Agency', 'AG2', hidden: true), new MtmAgency(3, 'Agency Three', 'AG3')],
            'advertisers' => [new MtmAdvertiser(1, 'Advertiser One', 'AD1'), new MtmAdvertiser(2, 'Advertiser Two', 'AD2')],
            'publishers' => [new MtmPublisher(1, 'Publisher One', 'PU1')],
            'users' => $users,
        ];
    }

    public function provider(string $type): InMemoryDataProvider
    {
        if ($this->providers === null) {
            $coordinator = new InMemorySnapshotCoordinator();
            $identify = static fn(object $item): string => \property_exists($item, 'id') && \is_int($item->id) ? (string) $item->id : '';
            $assignId = static function (object $item, string $id): void {
                \assert($item instanceof MtmUser);
                $item->id = (int) $id;
            };

            $this->providers = [];
            foreach (self::graph() as $name => $items) {
                $byId = [];
                foreach ($items as $item) {
                    $byId[(string) $item->id] = $item;
                }
                $this->providers[$name] = new InMemoryDataProvider($name, $byId, $identify, $assignId, coordinator: $coordinator);
            }
        }

        return $this->providers[$type];
    }

    public function persister(string $type): InMemoryDataPersister
    {
        return new InMemoryDataPersister(
            $type,
            $this->provider($type)->store(),
            static fn(): MtmUser => new MtmUser(),
            fn(string $relatedType, string $id): ?object => $this->provider($relatedType)->store()->find($id),
        );
    }
}
