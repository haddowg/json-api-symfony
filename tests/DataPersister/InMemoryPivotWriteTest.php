<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataPersister;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Schema\ResourceIdentifier;
use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The in-memory pivot write: the {@see InMemoryDataPersister} resolves the linkage ids to
 * the stored related objects, sets the parent's plain to-many property, and keeps each
 * member's pivot `meta` as a pivot row beside the parent in its store, leaving the related
 * objects as they are.
 */
final class InMemoryPivotWriteTest extends TestCase
{
    #[Test]
    public function aPivotMetaWriteStoresAPivotRowBesideTheParent(): void
    {
        // A parent POJO whose `tracks` is a plain object list (no place for pivot
        // data), and two stored related track POJOs the resolver hands back.
        $intro = new class ('1') {
            public function __construct(public string $id) {}
        };
        $outro = new class ('2') {
            public function __construct(public string $id) {}
        };
        $parent = new class {
            public string $id = '1';

            /** @var list<object> */
            public array $tracks = [];
        };

        $related = ['1' => $intro, '2' => $outro];
        $store = new InMemoryStore([], static function (object $item): string {
            /** @var string $id */
            $id = $item->id ?? '0';

            return $id;
        });
        $persister = new InMemoryDataPersister(
            'playlists',
            $store,
            static fn(): object => new \stdClass(),
            static fn(string $type, string $id): ?object => $related[$id] ?? null,
        );

        // The SAME belongsToMany pivot relation the Doctrine fixture declares, with a
        // WRITABLE position field.
        $relation = BelongsToMany::make('tracks', 'tracks')->fields(Integer::make('position')->build())->build();

        // A to-many linkage whose members carry pivot meta (position) — exactly the
        // write convention.
        $linkage = new ToManyRelationship([
            new ResourceIdentifier('tracks', '1', meta: ['pivot' => ['position' => 3]]),
            new ResourceIdentifier('tracks', '2', meta: ['pivot' => ['position' => 1]]),
        ]);

        $persister->mutateRelationship('playlists', $parent, $relation, $linkage, Mode::Replace);

        // The membership is set, each member's position is kept on its pivot row, and the
        // related objects are left as they are.
        self::assertSame([$intro, $outro], $parent->tracks);
        self::assertSame(['1' => ['position' => 3], '2' => ['position' => 1]], $store->pivotRows($parent, 'tracks'));
        self::assertObjectNotHasProperty('position', $intro);

        $persister->mutateRelationship('playlists', $parent, $relation, new ToManyRelationship([new ResourceIdentifier('tracks', '1')]), Mode::Remove);
        self::assertSame(['2' => ['position' => 1]], $store->pivotRows($parent, 'tracks'));
    }
}
