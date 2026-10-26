<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FillUsing;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\DataProvider\InMemorySnapshotCoordinator;

/**
 * The fillUsing seed shared by both kernels: agency 2 and member 2 are hidden, and
 * file 1 starts pointing at agency 1 and watched by member 1. The in-memory kernel
 * builds its stores from it once per container.
 */
final class FillUsingFixtures
{
    /**
     * @var array<string, InMemoryDataProvider>|null
     */
    private ?array $providers = null;

    /**
     * @return array{fill-agencies: list<FillAgency>, fill-members: list<FillMember>, fill-files: list<FillFile>}
     */
    public static function graph(): array
    {
        $file = new FillFile();
        $file->id = 1;
        $file->name = 'logo.png';
        $file->targetType = 'fill-agencies';
        $file->targetId = '1';
        $file->watcherIds = ['1'];

        return [
            'fill-agencies' => [new FillAgency(1, 'Agency One'), new FillAgency(2, 'Hidden Agency', hidden: true), new FillAgency(3, 'Agency Three')],
            'fill-members' => [new FillMember(1, 'Member One'), new FillMember(2, 'Hidden Member', hidden: true), new FillMember(3, 'Member Three')],
            'fill-files' => [$file],
        ];
    }

    public function provider(string $type): InMemoryDataProvider
    {
        if ($this->providers === null) {
            $coordinator = new InMemorySnapshotCoordinator();
            $identify = static fn(object $item): string => \property_exists($item, 'id') && \is_int($item->id) ? (string) $item->id : '';
            $assignId = static function (object $item, string $id): void {
                \assert($item instanceof FillFile);
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
            static fn(): FillFile => new FillFile(),
            fn(string $relatedType, string $id): ?object => $this->provider($relatedType)->store()->find($id),
        );
    }
}
