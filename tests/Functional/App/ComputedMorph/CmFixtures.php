<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedMorph;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\DataProvider\InMemorySnapshotCoordinator;

/**
 * The seed both kernels share. User 1 holds five companies across the three types,
 * one of them the hidden agency 2; user 2 holds one; user 3 holds two visible ones
 * and is the one the writes change. The in-memory kernel builds its stores from it
 * once per container, and its lookup reads them unscoped.
 */
final class CmFixtures implements CmCompanyLookup
{
    /**
     * @var array<string, InMemoryDataProvider>|null
     */
    private ?array $providers = null;

    /**
     * @return array{cm-agencies: list<CmAgency>, cm-advertisers: list<CmAdvertiser>, cm-publishers: list<CmPublisher>, cm-users: list<CmUser>}
     */
    public static function graph(): array
    {
        $users = [];
        foreach ([
            1 => ['all', ['cm-agencies:1', 'cm-advertisers:1', 'cm-publishers:1', 'cm-agencies:2', 'cm-advertisers:2']],
            2 => ['one', ['cm-advertisers:2']],
            3 => ['writes', ['cm-agencies:1', 'cm-publishers:1']],
        ] as $id => [$name, $companies]) {
            $user = new CmUser();
            $user->id = $id;
            $user->name = $name;
            foreach ($companies as $company) {
                [$type, $companyId] = \explode(':', $company);
                $user->addCompany($type, (int) $companyId);
            }
            $users[] = $user;
        }

        return [
            'cm-agencies' => [new CmAgency(1, 'Agency One', 'AG1'), new CmAgency(2, 'Hidden Agency', 'AG2', hidden: true)],
            'cm-advertisers' => [new CmAdvertiser(1, 'Advertiser One', 'AD1'), new CmAdvertiser(2, 'Advertiser Two', 'AD2')],
            'cm-publishers' => [new CmPublisher(1, 'Publisher One', 'PU1')],
            'cm-users' => $users,
        ];
    }

    public function find(string $type, int $id): ?object
    {
        return $this->provider($type)->store()->find((string) $id);
    }

    public function provider(string $type): InMemoryDataProvider
    {
        if ($this->providers === null) {
            $coordinator = new InMemorySnapshotCoordinator();
            $identify = static fn(object $item): string => \property_exists($item, 'id') && \is_int($item->id) ? (string) $item->id : '';
            $assignId = static function (object $item, string $id): void {
                \assert($item instanceof CmUser);
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
            static fn(): CmUser => new CmUser(),
            fn(string $relatedType, string $id): ?object => $this->provider($relatedType)->store()->find($id),
        );
    }
}
