<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Resource\Field\ForeignKey;
use haddowg\JsonApiBundle\DataProvider\ForeignKeyParents;

/**
 * Scopes a query rooted on the related entity to the rows whose foreign key holds one of
 * a page of parents' keys, and maps each row's foreign key back to its parent's wire id.
 *
 * The foreign key's column is either a single-valued association, compared by the id it
 * references (`IDENTITY(related.<column>)`) and bound through the target entity's id
 * type, or a mapped field, compared directly and bound through the field's type. A
 * `MorphTo` declared with `identifiedByColumns()` compares its id field the same way and
 * also keeps its type field to the values naming the parents' type, so a row pointing at
 * another type with the same id is never read. Every key, the parents' and the rows',
 * is compared in its database form ({@see DoctrineIdentifiers::fieldToDatabase()}), so a
 * `Uuid` the ORM hydrates and the bytes a binary column holds are one key. The parents are
 * never asked for their metadata, so they need not be entities.
 *
 * @internal
 */
final class ForeignKeyScope
{
    private const array INTEGER_TYPES = ['integer', 'smallint', 'bigint'];
    private const array TEXT_TYPES = ['string', 'text', 'ascii_string'];

    /**
     * @param ClassMetadata<object>  $related     the related entity
     * @param string                 $column      the field or association holding the parent's key
     * @param string|null            $typeColumn  the field holding a polymorphic foreign key's stored type
     * @param list<string>           $storedTypes the stored type values naming the parents' type
     * @param array<string, string>  $wireIdOf    each parent's key, in database form as a string, mapped to its wire id
     * @param list<mixed>            $bound       the parents' keys in their database form
     */
    private function __construct(
        private readonly DoctrineIdentifiers $ids,
        private readonly ClassMetadata $related,
        private readonly string $column,
        private readonly ?string $typeColumn,
        private readonly array $storedTypes,
        private readonly ForeignKeyParents $parents,
        private readonly array $wireIdOf,
        private readonly array $bound,
    ) {}

    /**
     * The scope of `$foreignKey` on `$relatedMetadata` for `$parents`.
     *
     * @param ClassMetadata<object> $relatedMetadata
     *
     * @throws \LogicException when the related entity maps no field or single-valued association for the foreign key's column
     */
    public static function of(EntityManagerInterface $entityManager, DoctrineIdentifiers $ids, ClassMetadata $relatedMetadata, ForeignKey $foreignKey, ForeignKeyParents $parents): self
    {
        [$column, $typeColumn, $storedTypes] = self::columns($relatedMetadata, $foreignKey, $parents->type);

        $wireIdOf = [];
        $bound = [];
        if ($typeColumn === null || $storedTypes !== []) {
            foreach ($parents->keys as $wireId => $key) {
                $database = $ids->fieldToDatabase($relatedMetadata, $column, $key);
                $normalised = self::normalise($database);
                if ($normalised === null) {
                    continue;
                }
                $wireIdOf[$normalised] = (string) $wireId;
                $bound[] = $database;
            }
        }

        return new self($ids, $relatedMetadata, $column, $typeColumn, $storedTypes, $parents, $wireIdOf, $bound);
    }

    /**
     * The field or association holding the parent's key, the field holding a polymorphic
     * foreign key's stored type, and the stored values naming `$parentType`.
     *
     * @param ClassMetadata<object> $relatedMetadata
     *
     * @return array{string, ?string, list<string>}
     *
     * @throws \LogicException when the related entity maps none of them
     */
    public static function columns(ClassMetadata $relatedMetadata, ForeignKey $foreignKey, string $parentType): array
    {
        $morph = $foreignKey->morphColumns();
        if ($morph !== null) {
            foreach ([$morph->type, $morph->id] as $field) {
                if (!$relatedMetadata->hasField($field)) {
                    throw self::unmappedMorph($relatedMetadata->getName(), $foreignKey, $field);
                }
            }

            return [$morph->id, $morph->type, $morph->storedValues($parentType)];
        }

        $column = $foreignKey->column;
        if (($relatedMetadata->hasAssociation($column) && $relatedMetadata->isSingleValuedAssociation($column)) || $relatedMetadata->hasField($column)) {
            return [$column, null, []];
        }

        throw self::unmapped($relatedMetadata->getName(), $foreignKey);
    }

    /**
     * The refusal of a foreign key the related entity maps nothing for.
     */
    public static function unmapped(string $relatedClass, ForeignKey $foreignKey): \LogicException
    {
        return new \LogicException(\sprintf(
            'The foreign key "%s" is stored in "%s", which %s maps as neither a field nor a single-valued association.',
            $foreignKey->name,
            $foreignKey->column,
            $relatedClass,
        ));
    }

    /**
     * The refusal of a polymorphic foreign key whose type or id field the related entity
     * doesn't map.
     */
    public static function unmappedMorph(string $relatedClass, ForeignKey $foreignKey, string $field): \LogicException
    {
        return new \LogicException(\sprintf(
            'The foreign key "%s" is a MorphTo keeping its target in "%s", which %s doesn\'t map as a field.',
            $foreignKey->name,
            $field,
            $relatedClass,
        ));
    }

    /**
     * The DQL condition that correlates the related entity under `$relatedAlias` with the
     * owner under `$ownerAlias`, a `$ownerType` row, through `$foreignKey`, as a filter's
     * hop over the relation joins them. A polymorphic foreign key's stored type values bind
     * to `:$placeholder` on `$bindOn`.
     *
     * @param ClassMetadata<object> $relatedMetadata
     * @param ClassMetadata<object> $ownerMetadata
     */
    public static function correlate(QueryBuilder $bindOn, ClassMetadata $relatedMetadata, string $relatedAlias, ForeignKey $foreignKey, string $ownerType, ClassMetadata $ownerMetadata, string $ownerAlias, string $placeholder): string
    {
        [$column, $typeColumn, $storedTypes] = self::columns($relatedMetadata, $foreignKey, $ownerType);
        if ($typeColumn !== null && $storedTypes === []) {
            return '1 = 0';
        }

        $condition = $relatedMetadata->hasAssociation($column)
            ? DoctrineIdentifiers::joins($relatedAlias . '.' . $column, $ownerMetadata, $ownerAlias)
            : \sprintf('%s.%s = %s', $relatedAlias, $column, DoctrineIdentifiers::path($ownerMetadata, $ownerAlias));
        if ($typeColumn === null) {
            return $condition;
        }

        $bindOn->setParameter($placeholder, $storedTypes);

        return \sprintf('%s.%s IN (:%s) AND %s', $relatedAlias, $typeColumn, $placeholder, $condition);
    }

    /**
     * Whether SQL can compare the related entity's foreign key with the owner's id column
     * as they are stored, which a filter's hop over the relation needs: an association
     * always can, and a field can when both have the same Doctrine type, or both are
     * integers or both text.
     *
     * @param ClassMetadata<object> $relatedMetadata
     * @param ClassMetadata<object> $ownerMetadata
     */
    public static function comparableInSql(ClassMetadata $relatedMetadata, ForeignKey $foreignKey, string $ownerType, ClassMetadata $ownerMetadata): bool
    {
        [$column] = self::columns($relatedMetadata, $foreignKey, $ownerType);
        if ($relatedMetadata->hasAssociation($column)) {
            return true;
        }
        if ($ownerMetadata->isIdentifierComposite || $ownerMetadata->hasAssociation($ownerMetadata->getSingleIdentifierFieldName())) {
            return false;
        }

        $stored = $relatedMetadata->getTypeOfField($column);
        $id = $ownerMetadata->getTypeOfField($ownerMetadata->getSingleIdentifierFieldName());

        return $stored === $id
            || (\in_array($stored, self::INTEGER_TYPES, true) && \in_array($id, self::INTEGER_TYPES, true))
            || (\in_array($stored, self::TEXT_TYPES, true) && \in_array($id, self::TEXT_TYPES, true));
    }

    /**
     * The same scope narrowed to the parent with `$wireId`.
     */
    public function only(string $wireId): self
    {
        $parents = $this->parents->only($wireId);
        $wireIdOf = \array_filter($this->wireIdOf, static fn(string $candidate): bool => $candidate === $wireId);
        $bound = [];
        foreach ($this->bound as $database) {
            $normalised = self::normalise($database);
            if ($normalised !== null && isset($wireIdOf[$normalised])) {
                $bound[] = $database;
            }
        }

        return new self($this->ids, $this->related, $this->column, $this->typeColumn, $this->storedTypes, $parents, $wireIdOf, $bound);
    }

    /**
     * The parents this scope reads for.
     */
    public function parents(): ForeignKeyParents
    {
        return $this->parents;
    }

    /**
     * The DQL expression of the foreign key on the related entity under `$alias`.
     */
    public function expression(string $alias): string
    {
        return $this->related->hasAssociation($this->column)
            ? \sprintf('IDENTITY(%s.%s)', $alias, $this->column)
            : \sprintf('%s.%s', $alias, $this->column);
    }

    /**
     * Restricts `$builder`'s related entity under `$alias` to the parents' rows and, with
     * `$select`, projects the foreign key as the parent discriminator.
     */
    public function scope(QueryBuilder $builder, string $alias, bool $select = false): QueryBuilder
    {
        if ($select) {
            $builder->addSelect(\sprintf('%s AS %s', $this->expression($alias), BatchScope::PARENT_DISCRIMINATOR_ALIAS));
        }

        if ($this->bound === []) {
            return $builder->andWhere('1 = 0');
        }

        if ($this->typeColumn !== null) {
            $builder->andWhere(\sprintf('%s.%s IN (:jsonapi_fk_types)', $alias, $this->typeColumn))
                ->setParameter('jsonapi_fk_types', $this->storedTypes);
        }

        $builder->andWhere(\sprintf('%s IN (:jsonapi_fk_parents)', $this->expression($alias)));
        $type = $this->ids->fieldType($this->related, $this->column);

        return $type === null
            ? $builder->setParameter('jsonapi_fk_parents', $this->bound)
            : $builder->setParameter('jsonapi_fk_parents', $this->bound, DoctrineIdentifiers::listType($type));
    }

    /**
     * The wire id of the parent whose key a row's foreign key `$raw` holds, in whatever
     * form the query returned it, or `null` when it is none of the parents'.
     */
    public function wireIdOf(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $normalised = $this->ids->fieldKey($this->related, $this->column, $raw);

        return $normalised === null ? null : ($this->wireIdOf[$normalised] ?? null);
    }

    private static function normalise(mixed $value): ?string
    {
        return \is_scalar($value) || $value instanceof \Stringable ? (string) $value : null;
    }
}
