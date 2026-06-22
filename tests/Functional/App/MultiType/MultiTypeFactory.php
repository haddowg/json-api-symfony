<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MultiType;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds the in-memory graph for the multi-type-per-entity conformance suite. The
 * crux: TWO providers — `members` (full) and `public-members` (curated) — read the
 * SAME {@see Member} objects, so the one record is served under two JSON:API types.
 * A third `posts` provider holds {@see Post} objects whose `author` points at a
 * Member, and a `posts` persister resolves the curated `public-members` linkage id
 * back to that same Member for relationship mutation.
 *
 * Seed: member 1 "Ada" (private email/secretNote), member 2 "Bob". Post 1 "Hello"
 * authored by Ada; post 2 "Draft" with no author.
 */
final class MultiTypeFactory
{
    private static ?InMemoryDataProvider $members = null;

    private static ?InMemoryDataProvider $publicMembers = null;

    private static ?InMemoryDataProvider $posts = null;

    /**
     * The one id-keyed Member graph both the `members` and `public-members`
     * providers read — built once so the two providers serve the SAME objects.
     *
     * @var array<int|string, Member>|null
     */
    private static ?array $memberSeed = null;

    public static function createMembers(): InMemoryDataProvider
    {
        return self::members();
    }

    public static function createPublicMembers(): InMemoryDataProvider
    {
        return self::publicMembers();
    }

    public static function createPosts(): InMemoryDataProvider
    {
        return self::posts();
    }

    public static function createPostsPersister(): InMemoryDataPersister
    {
        $members = self::members();

        return new InMemoryDataPersister(
            'posts',
            self::posts()->store(),
            static fn(): Post => new Post(),
            // The relation's declared target type is `public-members`; resolve it back
            // to the stored Member (the SAME objects the `members` provider serves) so a
            // relationship mutation sets the parent's author association.
            static function (string $type, string $id) use ($members): ?object {
                return $type === 'public-members' ? $members->store()->find($id) : null;
            },
        );
    }

    /**
     * Resets the per-kernel singletons so each test boots a fresh, unmutated graph.
     */
    public static function reset(): void
    {
        self::$members = null;
        self::$publicMembers = null;
        self::$posts = null;
        self::$memberSeed = null;
    }

    private static function members(): InMemoryDataProvider
    {
        return self::$members ??= self::buildMembers();
    }

    private static function publicMembers(): InMemoryDataProvider
    {
        // The curated provider reads the SAME id-keyed Member objects, so a read
        // through `public-members` is the same record a read through `members`
        // returns — one entity, two types.
        return self::$publicMembers ??= new InMemoryDataProvider(
            'public-members',
            self::memberSeed(),
            self::memberIdentify(),
            self::memberAssignId(),
        );
    }

    private static function posts(): InMemoryDataProvider
    {
        return self::$posts ??= self::buildPosts();
    }

    /**
     * @return array<int|string, Member>
     */
    private static function memberSeed(): array
    {
        return self::$memberSeed ??= [
            '1' => new Member(1, 'Ada', 'ada@example.test', 'launch codes'),
            '2' => new Member(2, 'Bob', 'bob@example.test', 'gone fishing'),
        ];
    }

    private static function buildMembers(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('members', self::memberSeed(), self::memberIdentify(), self::memberAssignId());
    }

    private static function buildPosts(): InMemoryDataProvider
    {
        $ada = self::members()->store()->find('1');
        \assert($ada instanceof Member);

        $posts = [
            '1' => new Post(1, 'Hello', $ada),
            '2' => new Post(2, 'Draft', null),
        ];

        return new InMemoryDataProvider(
            'posts',
            $posts,
            static function (object $item): string {
                \assert($item instanceof Post);

                return $item->id === null ? '' : (string) $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof Post);

                $item->id = (int) $id;
            },
        );
    }

    /**
     * @return \Closure(object): string
     */
    private static function memberIdentify(): \Closure
    {
        return static function (object $item): string {
            \assert($item instanceof Member);

            return $item->id === null ? '' : (string) $item->id;
        };
    }

    /**
     * @return \Closure(object, string): void
     */
    private static function memberAssignId(): \Closure
    {
        return static function (object $item, string $id): void {
            \assert($item instanceof Member);

            $item->id = (int) $id;
        };
    }
}
