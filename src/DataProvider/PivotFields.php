<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Sort\SortByField;

/**
 * Reads a {@see BelongsToMany} relation's declared pivot fields
 * ({@see BelongsToMany::pivotFields()} — real {@see FieldInterface} definitions) and
 * derives the host's interpretation of them: the recognised `sort=` vocabulary keys
 * (one {@see SortByField} per field, keyed by the field name and columned by its
 * declared {@see FieldInterface::column()}) and the per-field value cast applied to
 * the raw pivot column read off the association entity before it renders as
 * relationship meta.
 *
 * Pivot FILTERS are author-declared, not auto-derived: an app declares each pivot
 * filter explicitly in the relation's {@see RelationInterface::filters()} as a normal
 * core filter whose column is `pivot.`-prefixed (bundle ADR 0067). Sorts still
 * auto-derive (`?sort=position` works zero-config) — a deliberate filter/sort
 * asymmetry. The cast threads onto an author-declared pivot filter by the stripped
 * pivot column via {@see fieldForColumn()}.
 *
 * The cast is the field's OWN serialization (`Integer` → int, `DateTime` →
 * ISO-8601 string, …): the field knows its type, so the bundle no longer carries an
 * ad-hoc type-switch. A pivot field's `name()` is the wire/vocabulary key and its
 * `column()` (defaulting to the name, honouring `->storedAs()`) is the
 * association-entity column the value is read from / filtered / sorted on.
 */
final class PivotFields
{
    /**
     * The declared pivot fields for `$relation`, as a list of {@see FieldInterface}
     * definitions, or an empty list when it is not a pivot-backed relation.
     *
     * @return list<FieldInterface>
     */
    public static function declaredFor(RelationInterface $relation): array
    {
        return $relation instanceof BelongsToMany ? $relation->pivotFields() : [];
    }

    /**
     * The declared pivot fields keyed by their wire name, so a `filter[<name>]` /
     * `sort=<name>` key can be resolved back to the {@see FieldInterface} that backs
     * it (its column + value cast). Empty for a non-pivot relation.
     *
     * @return array<string, FieldInterface>
     */
    public static function byName(RelationInterface $relation): array
    {
        $byName = [];
        foreach (self::declaredFor($relation) as $field) {
            $byName[$field->name()] = $field;
        }

        return $byName;
    }

    /**
     * The declared pivot field whose column (its {@see FieldInterface::column()},
     * defaulting to its {@see FieldInterface::name()}) equals `$column`, or null when
     * none matches. The join is the STRIPPED pivot column (`position`, not
     * `pivot.position` and not the filter key, which may differ from the column), so an
     * author-declared `pivot.`-prefixed filter resolves its value cast against the field
     * backing the same association-entity column ({@see \haddowg\JsonApiBundle\DataProvider\RelationCriteriaFactory}).
     */
    public static function fieldForColumn(RelationInterface $relation, string $column): ?FieldInterface
    {
        foreach (self::declaredFor($relation) as $field) {
            if (($field->column() ?? $field->name()) === $column) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The sort vocabulary derived from `$relation`'s pivot fields: one
     * {@see SortByField} per field, keyed by the field name and columned by its
     * declared column (defaulting to the name), so `?sort=position` orders by the
     * pivot entity's backing column. Empty for a non-pivot relation.
     *
     * @return list<SortByField>
     */
    public static function sortsFor(RelationInterface $relation): array
    {
        $sorts = [];
        foreach (self::declaredFor($relation) as $field) {
            $sorts[] = SortByField::make($field->name(), $field->column() ?? $field->name());
        }

        return $sorts;
    }

    /**
     * Casts a raw pivot column value to its serialized (wire) representation via the
     * field's OWN serialization — an `Integer` field yields an int, a `DateTime`
     * field an ISO-8601 string — so a rendered pivot meta member carries a typed
     * value. The field reads its backing column off a tiny array carrier through its
     * request-free serializer, so no request and no `serializeUsing`/`extractUsing`
     * hook is consulted (a pivot field is a plain field definition); a `null` stays
     * `null`.
     */
    public static function cast(mixed $value, FieldInterface $field): mixed
    {
        return $field->serializeWithoutRequest([$field->column() ?? $field->name() => $value]);
    }
}
