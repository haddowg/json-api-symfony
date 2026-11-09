<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataProvider;

use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApiBundle\DataProvider\PivotFields;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A computed pivot field has no column, so it never becomes a sort key or the field a
 * `pivot.`-prefixed filter casts through, though it is still declared. A stored field read
 * through an extract hook keeps its column.
 */
#[CoversClass(PivotFields::class)]
final class PivotFieldsComputedTest extends TestCase
{
    #[Test]
    public function onlyStoredFieldsBackSortsAndFilterCasts(): void
    {
        $relation = BelongsToMany::make('members', 'users')->fields(
            Integer::make('position'),
            Str::make('role')->computedUsing(static fn(): string => 'r'),
            Str::make('label')->extractUsing(static fn(): string => 'l'),
        )->build();

        self::assertSame(['position', 'label'], \array_map(static fn(SortByField $sort): string => $sort->key(), PivotFields::sortsFor($relation)));
        self::assertSame(['position', 'label'], \array_keys(PivotFields::byName($relation)));
        self::assertNull(PivotFields::fieldForColumn($relation, 'role'));
        self::assertNotNull(PivotFields::fieldForColumn($relation, 'label'));
        self::assertCount(3, PivotFields::declaredFor($relation));
        self::assertTrue(PivotFields::hasComputed($relation));
        self::assertFalse(PivotFields::hasComputed(HasMany::make('members', 'users')->build()));
    }

    #[Test]
    public function aStoredFieldWithAnExtractHookIsReadThroughAHook(): void
    {
        $hooked = BelongsToMany::make('members', 'users')->fields(
            Integer::make('position'),
            Str::make('label')->extractUsing(static fn(): string => 'l'),
        )->build();

        self::assertTrue(PivotFields::readsThroughHook($hooked));
        self::assertFalse(PivotFields::hasComputed($hooked));
        self::assertFalse(PivotFields::readsThroughHook(BelongsToMany::make('members', 'users')->fields(Integer::make('position'))->build()));
    }
}
