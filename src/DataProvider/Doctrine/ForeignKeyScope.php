<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\DBAL\Types\Type;
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
 * type, or a mapped field, compared directly and bound through the field's type. The
 * parents are never asked for their metadata, so they need not be entities.
 *
 * @internal
 */
final class ForeignKeyScope
{
    /**
     * @param ClassMetadata<object>|null $target    the association's target entity, or `null` for a field
     * @param array<string, string>      $wireIdOf  each parent's normalised key mapped to its wire id
     * @param list<mixed>                $bound     the parents' keys in their database form
     */
    private function __construct(
        private readonly DoctrineIdentifiers $ids,
        private readonly string $column,
        private readonly ?ClassMetadata $target,
        private readonly ?Type $fieldType,
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
        $column = $foreignKey->column;
        $target = null;
        $fieldType = null;
        if ($relatedMetadata->hasAssociation($column) && $relatedMetadata->isSingleValuedAssociation($column)) {
            $target = $entityManager->getClassMetadata($relatedMetadata->getAssociationTargetClass($column));
        } elseif ($relatedMetadata->hasField($column)) {
            $typeName = $relatedMetadata->getTypeOfField($column);
            $fieldType = \is_string($typeName) ? Type::getType($typeName) : null;
        } else {
            throw self::unmapped($relatedMetadata->getName(), $foreignKey);
        }

        $wireIdOf = [];
        $bound = [];
        $platform = $entityManager->getConnection()->getDatabasePlatform();
        foreach ($parents->keys as $wireId => $key) {
            $database = match (true) {
                $target !== null => $ids->toDatabase($target, $key),
                $fieldType !== null => $fieldType->convertToDatabaseValue($key, $platform),
                default => $key,
            };
            $normalised = self::normalise($database);
            if ($normalised === null) {
                continue;
            }
            $wireIdOf[$normalised] = (string) $wireId;
            $bound[] = $database;
        }

        return new self($ids, $column, $target, $fieldType, $parents, $wireIdOf, $bound);
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

        return new self($this->ids, $this->column, $this->target, $this->fieldType, $parents, $wireIdOf, $bound);
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
        return $this->target !== null
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

        $builder->andWhere(\sprintf('%s IN (:jsonapi_fk_parents)', $this->expression($alias)));
        $type = $this->target !== null ? $this->ids->type($this->target) : $this->fieldType;

        return $type === null
            ? $builder->setParameter('jsonapi_fk_parents', $this->bound)
            : $builder->setParameter('jsonapi_fk_parents', $this->bound, DoctrineIdentifiers::listType($type));
    }

    /**
     * The wire id of the parent whose key a row's foreign key `$raw` holds, read from the
     * database, or `null` when it is none of the parents'.
     */
    public function wireIdOf(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        if ($this->target !== null) {
            $raw = $this->ids->toDatabase($this->target, $this->ids->toPhp($this->target, $raw));
        }

        $normalised = self::normalise($raw);

        return $normalised === null ? null : ($this->wireIdOf[$normalised] ?? null);
    }

    private static function normalise(mixed $value): ?string
    {
        return \is_scalar($value) || $value instanceof \Stringable ? (string) $value : null;
    }
}
