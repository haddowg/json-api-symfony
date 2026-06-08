<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\CollectionResult;
use haddowg\JsonApiBundle\DataProvider\CriteriaApplier;
use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;

/**
 * The reference Doctrine ORM read provider, wired only when `doctrine/orm` is
 * installed **and** at least one resource maps an entity (the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\DoctrineEntityMapPass}
 * removes the service otherwise), because Doctrine is a `require-dev` +
 * `suggest` dependency, not a hard one.
 *
 * A collection fetch is one `QueryBuilder` pipeline: the shared
 * {@see CriteriaApplier} matches the requested `filter[…]`/`sort` parameters
 * against the declared vocabularies and pushes each down through the
 * {@see DoctrineFilterHandler}/{@see DoctrineSortHandler}; a windowed fetch
 * then runs a `COUNT` over the filtered (un-ordered, un-windowed) query before
 * applying the window as `LIMIT`/`OFFSET` — items are never over-fetched.
 *
 * The `type → entity-class` map is populated by the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\DoctrineEntityMapPass}
 * from each resource's `#[AsJsonApiResource(entity: …)]` declaration.
 *
 * One instance serves every entity-mapped type — a different entity class per
 * type — so `TEntity` cannot narrow past `object`.
 *
 * @implements DataProviderInterface<object>
 */
final class DoctrineDataProvider implements DataProviderInterface
{
    /**
     * The root alias every generated QueryBuilder uses; handlers re-read it
     * from the builder, so this is a naming choice, not a contract.
     */
    private const string ROOT_ALIAS = 'resource';

    private readonly CriteriaApplier $applier;

    private readonly DoctrineFilterHandler $filterHandler;

    private readonly DoctrineSortHandler $sortHandler;

    /**
     * @param array<string, class-string> $entityClassByType a `type → entity FQCN` map
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly array $entityClassByType,
    ) {
        $this->applier = new CriteriaApplier();
        $this->filterHandler = new DoctrineFilterHandler();
        $this->sortHandler = new DoctrineSortHandler();
    }

    public function supports(string $type): bool
    {
        return isset($this->entityClassByType[$type]);
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return $this->entityManager->find($this->entityClassFor($type), $id);
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        $builder = $this->entityManager
            ->getRepository($this->entityClassFor($type))
            ->createQueryBuilder(self::ROOT_ALIAS);

        $builder = $this->applier->apply($criteria, $builder, $this->filterHandler, $this->sortHandler);

        $window = $criteria->window;
        if ($window === null) {
            return new CollectionResult($this->items($builder));
        }

        if (!$window instanceof OffsetWindow) {
            throw new \LogicException(\sprintf(
                'The %s can only execute a %s pagination window; got %s.',
                self::class,
                OffsetWindow::class,
                \get_debug_type($window),
            ));
        }

        $total = $this->count($builder);
        $builder->setFirstResult($window->offset)->setMaxResults($window->limit);

        return new CollectionResult($this->items($builder), $total);
    }

    /**
     * @return list<object>
     */
    private function items(QueryBuilder $builder): array
    {
        /** @var list<object> */
        return $builder->getQuery()->getResult();
    }

    /**
     * The total of the filtered collection: the same builder re-selected as a
     * `COUNT` of the root entity, with ordering dropped (it cannot change the
     * count) and no window applied.
     */
    private function count(QueryBuilder $builder): int
    {
        $counter = clone $builder;
        $counter->resetDQLPart('orderBy');
        $counter->select(\sprintf('COUNT(%s)', self::ROOT_ALIAS));

        $total = $counter->getQuery()->getSingleScalarResult();

        return \is_numeric($total) ? (int) $total : 0;
    }

    /**
     * @return class-string
     */
    private function entityClassFor(string $type): string
    {
        return $this->entityClassByType[$type]
            ?? throw new \LogicException(\sprintf('No Doctrine entity class is mapped for JSON:API type "%s".', $type));
    }
}
