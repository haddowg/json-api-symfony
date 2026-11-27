<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SelfThrough;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use Symfony\Component\Uid\Uuid;

/**
 * Three owners of each key form, whose ids differ only in their last digit: owner 1 seats
 * owner 2 with rank 1, and owner 2 seats owner 3 with rank 2. Read from the wrong side,
 * owner 1's peers would be itself, or nobody.
 */
final class StFixtures
{
    public const array TYPES = [
        'st-owners' => [StOwner::class, StSeat::class],
        'st-uuid-owners' => [StUuidOwner::class, StUuidSeat::class],
        'st-guid-owners' => [StGuidOwner::class, StGuidSeat::class],
    ];

    /**
     * @var array<string, InMemoryDataProvider>|null
     */
    private ?array $providers = null;

    /**
     * The stored key of owner `$n` (1 to 4) of `$type`.
     */
    public static function key(string $type, int $n): int|string|Uuid
    {
        return match ($type) {
            'st-owners' => $n,
            'st-uuid-owners' => self::uuid($n),
            default => self::guid($n),
        };
    }

    private static function uuid(int $n): Uuid
    {
        return Uuid::fromString(\sprintf('0190a0b0-1c2d-7e3f-8a4b-5c6d7e8f900%d', $n));
    }

    private static function guid(int $n): string
    {
        return \sprintf('C0FFEE00-AB12-4CD3-9EF4-ABCDEF00000%d', $n);
    }

    /**
     * The wire id of owner `$n` of `$type`.
     */
    public static function wireId(string $type, int $n): string
    {
        $key = self::key($type, $n);

        return $key instanceof Uuid ? 'own_' . $key->toRfc4122() : (string) $key;
    }

    /**
     * Owner `$n`'s number, read off any form of its key.
     */
    public static function number(mixed $key): int
    {
        $string = $key instanceof Uuid ? $key->toRfc4122() : (string) (\is_scalar($key) ? $key : '');

        return (int) \substr($string, -1);
    }

    /**
     * The seeded owners of `$type`, with their seats on Doctrine or their member lists in
     * memory.
     *
     * @return list<StOwner|StUuidOwner|StGuidOwner>
     */
    public static function owners(string $type, bool $doctrine): array
    {
        $owners = [];
        foreach ([1, 2, 3] as $n) {
            $owners[] = match ($type) {
                'st-owners' => new StOwner($n, 'Owner ' . $n),
                'st-uuid-owners' => new StUuidOwner(self::uuid($n), 'Owner ' . $n),
                default => new StGuidOwner(self::guid($n), 'Owner ' . $n),
            };
        }

        foreach ([[0, 1, 1], [1, 2, 2]] as [$from, $to, $rank]) {
            self::seat($owners[$from], $owners[$to], $rank, $doctrine);
        }

        return $owners;
    }

    private static function seat(StOwner|StUuidOwner|StGuidOwner $owner, StOwner|StUuidOwner|StGuidOwner $peer, int $rank, bool $doctrine): void
    {
        if (!$doctrine) {
            $owner->peers[] = $peer;
            $peer->followers[] = $owner;

            return;
        }

        match (true) {
            $owner instanceof StOwner && $peer instanceof StOwner => $owner->seats->add(new StSeat(null, $owner, $peer, $rank)),
            $owner instanceof StUuidOwner && $peer instanceof StUuidOwner => $owner->seats->add(new StUuidSeat(null, $owner, $peer, $rank)),
            $owner instanceof StGuidOwner && $peer instanceof StGuidOwner => $owner->seats->add(new StGuidSeat(null, $owner, $peer, $rank)),
            default => throw new \LogicException('A seat links two owners of one class.'),
        };
    }

    public function provider(string $type): InMemoryDataProvider
    {
        if ($this->providers === null) {
            $this->providers = [];
            foreach (\array_keys(self::TYPES) as $name) {
                $byId = [];
                foreach (self::owners($name, false) as $owner) {
                    $byId[self::wireIdOf($owner)] = $owner;
                }
                $this->providers[$name] = new InMemoryDataProvider($name, $byId, self::wireIdOf(...));
            }
        }

        return $this->providers[$type];
    }

    public function persister(string $type): InMemoryDataPersister
    {
        $ownerClass = self::TYPES[$type][0];

        return new InMemoryDataPersister(
            $type,
            $this->provider($type)->store(),
            static fn(): object => new $ownerClass(),
            fn(string $relatedType, string $id): ?object => $this->provider($relatedType)->store()->find($id),
        );
    }

    private static function wireIdOf(object $owner): string
    {
        $id = $owner instanceof StOwner || $owner instanceof StUuidOwner || $owner instanceof StGuidOwner ? $owner->id : null;

        return $id instanceof Uuid ? 'own_' . $id->toRfc4122() : (string) $id;
    }
}
