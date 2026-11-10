<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\LinkRows\InMemoryLinkRows;
use haddowg\JsonApi\Collection\LinkRows\LinkRowKeysInterface;
use haddowg\JsonApi\Collection\LinkRows\LinkRowQuery;
use haddowg\JsonApi\Collection\LinkRows\LinkRowQueryInterface;
use haddowg\JsonApi\Collection\WindowExecutor;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Schema\Relationship\IdentifiedMember;
use haddowg\JsonApiBundle\DataProvider\ReadsLinkRowsInterface;

/**
 * Queries a `MorphToMany` declared with `identifiedByRows()` from its link-row fields
 * alone: rows of its `through()` entity whose owner field is the parent and whose type
 * counts, a narrowed type only for its admitted storage keys, filtered and sorted on the
 * `pivot` alias, so a `pivot.`-prefixed column names the row's own field, then ordered by
 * core's tiebreak and paged, all in one query. Each row becomes the member core's query
 * maps it to. The keys a batch of parents link, which narrowing a guarded type needs, are
 * one key-only read of the same entity, and the rows a page of parents renders as linkage
 * are one read of it.
 *
 * A relation declared with an `identifiedBy()` closure gives no fields to query, so its
 * rows are read through the closure and queried in memory by core's
 * {@see InMemoryLinkRows}.
 *
 * @internal
 */
final class DoctrineLinkRows implements LinkRowQueryInterface, LinkRowKeysInterface, ReadsLinkRowsInterface
{
    private const string ALIAS = 'pivot';

    private readonly WindowExecutor $windowExecutor;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DoctrineFilterHandler $filterHandler,
        private readonly DoctrineSortHandler $sortHandler,
    ) {
        $this->windowExecutor = new WindowExecutor();
    }

    public function queryLinkRows(mixed $parent, MorphToMany $relation, LinkRowQuery $query, JsonApiRequestInterface $request): CollectionResult
    {
        $fields = $query->fields;
        if ($fields === null) {
            return (new InMemoryLinkRows())->queryLinkRows($parent, $relation, $query, $request);
        }

        $through = $fields->through;
        if (!\class_exists($through)) {
            throw new \LogicException(\sprintf('MorphToMany relation "%s" names the link-row class "%s" with through(), which does not exist.', $relation->name(), $through));
        }

        $builder = $this->entityManager->createQueryBuilder()
            ->select(self::ALIAS)
            ->from($through, self::ALIAS)
            ->where(\sprintf('%s.%s = :jsonapi_owner', self::ALIAS, $fields->owner))
            ->setParameter('jsonapi_owner', $parent);

        $this->whereTypeCounts($builder, $query, $fields->type, $fields->id);

        foreach ($query->filters as $directive) {
            $builder = $this->filterHandler->applyOn($directive->filter, $builder, $directive->value, self::ALIAS);
        }
        if ($query->sorts !== []) {
            $builder = $this->sortHandler->applyOn($query->sorts, $builder, self::ALIAS);
        }
        foreach ($query->tiebreak() as $field) {
            $builder->addOrderBy(\sprintf('%s.%s', self::ALIAS, $field), 'ASC');
        }

        $members = fn(QueryBuilder $page): array => $this->members($page, $query);

        return $this->windowExecutor->run(
            $query->window,
            $query->countable,
            all: fn(): array => $members($builder),
            count: fn(): int => $this->count($builder),
            page: fn(int $offset, int $limit): array => $members((clone $builder)->setFirstResult($offset)->setMaxResults($limit)),
            probe: fn(int $offset, int $limit): array => $members((clone $builder)->setFirstResult($offset)->setMaxResults($limit)),
        );
    }

    /**
     * One read of the `through()` entity for the whole batch, selecting only the owner,
     * type and id fields of the rows whose owner is one of the parents and whose type is a
     * stored value of one of `$types`.
     */
    public function linkRowKeys(array $parents, MorphToMany $relation, array $types, JsonApiRequestInterface $request): array
    {
        $fields = $relation->linkRowFields();
        if ($fields === null) {
            return (new InMemoryLinkRows())->linkRowKeys($parents, $relation, $types, $request);
        }

        $typeOf = [];
        foreach ($types as $type) {
            foreach ($fields->storedValues($type) as $value) {
                $typeOf[$value] = $type;
            }
        }

        $positions = [];
        $owners = [];
        foreach ($parents as $index => $parent) {
            $key = \is_object($parent) ? $this->ownerKey($parent) : null;
            if ($key !== null) {
                $positions[$key][] = $index;
                $owners[] = $parent;
            }
        }

        $seen = \array_fill_keys(\array_keys($parents), []);
        if ($typeOf !== [] && $owners !== [] && \class_exists($fields->through)) {
            $rows = $this->entityManager->createQueryBuilder()
                ->select(\sprintf('IDENTITY(%1$s.%2$s) AS owner, %1$s.%3$s AS type, %1$s.%4$s AS id', self::ALIAS, $fields->owner, $fields->type, $fields->id))
                ->from($fields->through, self::ALIAS)
                ->where(\sprintf('%s.%s IN (:jsonapi_owners)', self::ALIAS, $fields->owner))
                ->andWhere(\sprintf('%s.%s IN (:jsonapi_types)', self::ALIAS, $fields->type))
                ->setParameter('jsonapi_owners', $owners)
                ->setParameter('jsonapi_types', \array_map('strval', \array_keys($typeOf)))
                ->getQuery()
                ->getArrayResult();

            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $stored = ($row['type'] ?? null) instanceof \BackedEnum ? $row['type']->value : $row['type'];
                $type = \is_scalar($stored) ? ($typeOf[(string) $stored] ?? null) : null;
                $id = $row['id'] ?? null;
                if ($type === null || (!\is_int($id) && !\is_string($id)) || $id === '' || !\is_scalar($row['owner'] ?? null)) {
                    continue;
                }
                foreach ($positions[(string) $row['owner']] ?? [] as $index) {
                    $seen[$index][$type][(string) $id] ??= $id;
                }
            }
        }

        $keys = [];
        foreach ($seen as $index => $byType) {
            $keys[$index] = [];
            foreach ($types as $type) {
                if (isset($byType[$type])) {
                    $keys[$index][$type] = \array_values($byType[$type]);
                }
            }
        }

        return $keys;
    }

    /**
     * One read of the `through()` entity for every row whose owner is one of the parents,
     * grouped by owner. A relation read through an `identifiedBy()` closure has no owner
     * field to read by, so each parent's rows come through its closure.
     */
    public function linkRowMembers(array $parents, MorphToMany $relation, JsonApiRequestInterface $request): array
    {
        $fields = $relation->linkRowFields();
        $members = \array_fill_keys(\array_keys($parents), []);
        if ($fields === null) {
            foreach ($parents as $index => $parent) {
                $members[$index] = $relation->identifiedMembers($parent, $request);
            }

            return $members;
        }

        $positions = [];
        $owners = [];
        foreach ($parents as $index => $parent) {
            $key = $this->ownerKey($parent);
            if ($key !== null) {
                $positions[$key][] = $index;
                $owners[] = $parent;
            }
        }
        if ($owners === [] || !\class_exists($fields->through)) {
            return $members;
        }

        $metadata = $this->entityManager->getClassMetadata($fields->through);
        $rows = $this->entityManager->createQueryBuilder()
            ->select(self::ALIAS)
            ->from($fields->through, self::ALIAS)
            ->where(\sprintf('%s.%s IN (:jsonapi_owners)', self::ALIAS, $fields->owner))
            ->setParameter('jsonapi_owners', $owners)
            ->getQuery()
            ->getResult();

        $seen = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            $owner = \is_object($row) ? $metadata->getFieldValue($row, $fields->owner) : null;
            $member = $fields->member($row);
            $key = \is_object($owner) ? $this->ownerKey($owner) : null;
            if ($member === null || $key === null) {
                continue;
            }

            foreach ($positions[$key] ?? [] as $index) {
                $pair = $member->type . "\0" . $member->id;
                if (!isset($seen[$index][$pair])) {
                    $seen[$index][$pair] = true;
                    $members[$index][] = $member;
                }
            }
        }

        return $members;
    }

    /**
     * The parent's single identifier as the key its rows' owner holds, or `null` when it
     * has none or a composite one.
     */
    private function ownerKey(object $parent): ?string
    {
        $values = $this->entityManager->getClassMetadata($parent::class)->getIdentifierValues($parent);
        $value = \count($values) === 1 ? \reset($values) : null;
        if (\is_object($value)) {
            $nested = $this->entityManager->getClassMetadata($value::class)->getIdentifierValues($value);
            $value = \count($nested) === 1 ? \reset($nested) : null;
        }

        return \is_scalar($value) ? (string) $value : null;
    }

    /**
     * A row counts when its type field holds a stored value of a type the query doesn't
     * narrow, or a narrowed type's value with one of its admitted ids. With neither, no
     * row counts.
     */
    private function whereTypeCounts(QueryBuilder $builder, LinkRowQuery $query, string $typeField, string $idField): void
    {
        $type = \sprintf('%s.%s', self::ALIAS, $typeField);
        $id = \sprintf('%s.%s', self::ALIAS, $idField);

        $counts = [];
        if ($query->typeValues() !== []) {
            $counts[] = $builder->expr()->in($type, ':jsonapi_types');
            $builder->setParameter('jsonapi_types', $query->typeValues());
        }

        $index = 0;
        foreach ($query->admittedIds() as $value => $ids) {
            $counts[] = $builder->expr()->andX(
                $builder->expr()->eq($type, ':jsonapi_narrowed_' . $index),
                $builder->expr()->in($id, ':jsonapi_admitted_' . $index),
            );
            $builder->setParameter('jsonapi_narrowed_' . $index, (string) $value);
            $builder->setParameter('jsonapi_admitted_' . $index, $ids);
            ++$index;
        }

        $builder->andWhere($counts === [] ? '1 = 0' : $builder->expr()->orX(...$counts));
    }

    /**
     * @return list<IdentifiedMember>
     */
    private function members(QueryBuilder $builder, LinkRowQuery $query): array
    {
        $rows = $builder->getQuery()->getResult();

        $members = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            $member = $query->member($row);
            if ($member !== null) {
                $members[] = $member;
            }
        }

        return $members;
    }

    private function count(QueryBuilder $builder): int
    {
        $counter = clone $builder;
        $counter->resetDQLPart('orderBy');
        $counter->select(\sprintf('COUNT(%s)', self::ALIAS));
        $total = $counter->getQuery()->getSingleScalarResult();

        return \is_numeric($total) ? (int) $total : 0;
    }
}
