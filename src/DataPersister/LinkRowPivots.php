<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\ResourceIdentifier;
use haddowg\JsonApiBundle\DataProvider\PivotFields;
use haddowg\JsonApiBundle\DataProvider\RelatedReadRouter;

/**
 * Reads and writes the pivot fields an identified `MorphToMany` keeps on its link rows.
 * Its `fillUsing()` hook owns which rows exist, since only the host knows how a row
 * names its member; this writes each member's `meta.pivot` onto the row the hook left
 * for it, so the hook never handles pivot values. Rows are matched to linkage by type
 * and wire id, through the rows the relation's `identifiedBy()` closure returns.
 *
 * @internal
 */
final class LinkRowPivots
{
    /**
     * Each member's stored pivot values, as their wire values, keyed by type and wire
     * id joined by a NUL byte.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function stored(MorphToMany $relation, object $parent, SerializerResolverInterface $resolver, JsonApiRequestInterface $request): array
    {
        $stored = [];
        foreach (self::rows($relation, $parent, $resolver, $request) as $key => $row) {
            $values = [];
            foreach ($relation->storedPivotFields() as $field) {
                $values[$field->name()] = PivotFields::cast(Accessor::get($row, $field->column() ?? $field->name()), $field);
            }
            $stored[$key] = $values;
        }

        return $stored;
    }

    /**
     * The linkage's members as the keys its rows are matched by, in order.
     *
     * @return list<string>
     */
    public static function keys(ToManyRelationship $linkage): array
    {
        $keys = [];
        foreach ($linkage->resourceIdentifiers as $identifier) {
            \assert($identifier instanceof ResourceIdentifier);
            if ($identifier->id !== null) {
                $keys[] = $identifier->type . "\0" . $identifier->id;
            }
        }

        return $keys;
    }

    /**
     * Writes each linkage member's `meta.pivot` onto its link row: the fields writable on
     * a new row for a member not in `$before`, the fields writable on an existing row
     * otherwise. A field the member doesn't send keeps its value. A remove carries no
     * pivot values.
     *
     * @param array<string, object> $before the members' rows before the write, keyed as {@see rows()} keys them
     */
    public static function write(
        MorphToMany $relation,
        object $parent,
        ToManyRelationship $linkage,
        Mode $mode,
        array $before,
        SerializerResolverInterface $resolver,
        JsonApiRequestInterface $request,
        ?StoresDateTimesInterface $dates = null,
    ): void {
        if ($mode === Mode::Remove || $relation->storedPivotFields() === []) {
            return;
        }

        $rows = self::rows($relation, $parent, $resolver, $request);
        foreach ($linkage->resourceIdentifiers as $identifier) {
            \assert($identifier instanceof ResourceIdentifier);
            $pivot = $identifier->meta['pivot'] ?? null;
            if ($identifier->id === null || !\is_array($pivot) || $pivot === []) {
                continue;
            }

            $key = $identifier->type . "\0" . $identifier->id;
            $row = $rows[$key] ?? null;
            if ($row === null) {
                continue;
            }

            foreach ($relation->writablePivotFields(!isset($before[$key])) as $field) {
                if (!\array_key_exists($field->name(), $pivot)) {
                    continue;
                }

                $column = $field->column() ?? $field->name();
                $value = $field->castWireValue($pivot[$field->name()]);
                Accessor::set($row, $column, $dates?->dateTimeForColumn($row, $column, $value) ?? $value);
            }
        }
    }

    /**
     * The parent's link rows keyed by member type and wire id, joined by a NUL byte.
     *
     * @return array<string, object>
     */
    public static function rows(MorphToMany $relation, object $parent, SerializerResolverInterface $resolver, JsonApiRequestInterface $request): array
    {
        $rows = [];
        foreach ($relation->identifiedMembers($parent, $request) as $member) {
            if (!\is_object($member->row) || !$resolver->hasSerializerFor($member->type)) {
                continue;
            }

            $rows[$member->type . "\0" . RelatedReadRouter::wireId($resolver->serializerFor($member->type), $member->id)] = $member->row;
        }

        return $rows;
    }
}
