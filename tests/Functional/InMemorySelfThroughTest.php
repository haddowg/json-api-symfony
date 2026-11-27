<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\SelfThrough\StFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\SelfThrough\StInMemoryTestKernel;

/**
 * The self-seating owners in memory, where each relation is its own member list, as the
 * witness the Doctrine seats are held to.
 */
final class InMemorySelfThroughTest extends SelfThroughConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return StInMemoryTestKernel::class;
    }

    protected function stored(string $type, int $n, string $relation): array
    {
        $fixtures = static::getContainer()->get(StFixtures::class);
        self::assertInstanceOf(StFixtures::class, $fixtures);
        $owner = $fixtures->provider($type)->store()->find(StFixtures::wireId($type, $n));
        self::assertIsObject($owner);
        $members = \property_exists($owner, $relation) && \is_iterable($owner->{$relation}) ? [...$owner->{$relation}] : [];

        $numbers = \array_map(static fn(mixed $member): int => \is_object($member) && \property_exists($member, 'id') ? StFixtures::number($member->id) : 0, $members);
        \sort($numbers);

        return $numbers;
    }
}
