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
 * The in-memory pivot WRITE boundary: pivot data is Doctrine-only (it needs an
 * association entity the in-memory provider cannot model), so a pivot-meta write is
 * IGNORED in-memory — consistent with read being unsupported there. The
 * {@see InMemoryDataPersister} resolves the linkage ids to the stored related objects
 * and sets the parent's plain to-many property; the per-member pivot `meta` carried
 * on each {@see ResourceIdentifier} is never consulted (the relation is not
 * pivot-backed in-memory — there is no join row to hold it).
 */
final class InMemoryPivotWriteIgnoreTest extends TestCase
{
    #[Test]
    public function aPivotMetaWriteIsIgnoredInMemory(): void
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
        // WRITABLE position field — meaningless in-memory.
        $relation = BelongsToMany::make('tracks', 'tracks')->fields(Integer::make('position'));

        // A to-many linkage whose members carry pivot meta (position) — exactly the
        // write convention. In-memory it is applied as a plain membership set.
        $linkage = new ToManyRelationship([
            new ResourceIdentifier('tracks', '1', meta: ['pivot' => ['position' => 3]]),
            new ResourceIdentifier('tracks', '2', meta: ['pivot' => ['position' => 1]]),
        ]);

        $persister->mutateRelationship('playlists', $parent, $relation, $linkage, Mode::Replace);

        // The membership is set (both tracks linked), but the meta is ignored: the
        // related objects carry no pivot value anywhere — they are the bare stored
        // objects, untouched by the position meta.
        self::assertSame([$intro, $outro], $parent->tracks);
        self::assertObjectNotHasProperty('position', $intro);
        self::assertObjectNotHasProperty('pivot', $intro);
    }
}
