<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApiBundle\DataProvider\RelatedReadRouter;
use haddowg\JsonApiBundle\DataProvider\RelationCriteriaFactory;

/**
 * The load state a server renders with. A to-many whose members come from its
 * provider's read, because the provider scopes it, a filter default narrows it, it
 * has a default read order or its linkage is cut to a first page, never counts as
 * loaded here. Core asks only when no
 * linkage was supplied for the relation, so its data is then left out rather than
 * read off the model, where a collection something else initialised after the
 * {@see \haddowg\JsonApiBundle\DataProvider\RelatedIncludeBatcher} ran still holds
 * every row.
 *
 * The batcher decides what to load from {@see isLoadedOnModel()}, the wrapped
 * storage answer.
 */
final class ProviderReadLoadState implements RelationshipLoadStateInterface
{
    /**
     * @param \Closure(string): ?AbstractResource                       $resourceFor       the server's resource for a type
     * @param (\Closure(RelationInterface): ?PaginatorInterface)|null $linkagePaginator the paginator the server cuts a relation's rendered linkage to
     */
    public function __construct(
        private readonly RelationshipLoadStateInterface $inner,
        private readonly RelatedReadRouter $router,
        private readonly \Closure $resourceFor,
        private readonly RelationCriteriaFactory $relationCriteria = new RelationCriteriaFactory(),
        private readonly ?\Closure $linkagePaginator = null,
    ) {}

    public function isRelationshipLoaded(mixed $model, RelationInterface $relation): bool
    {
        if ($this->router->readsByForeignKey($relation) || !$this->inner->isRelationshipLoaded($model, $relation)) {
            return false;
        }

        return !$relation->isToMany() || !$this->readsThroughProvider($relation);
    }

    /**
     * Whether the model already holds the relation's value, as storage reports it. It
     * never holds a relation read through a foreign key.
     */
    public function isLoadedOnModel(mixed $model, RelationInterface $relation): bool
    {
        return !$this->router->readsByForeignKey($relation) && $this->inner->isRelationshipLoaded($model, $relation);
    }

    private function readsThroughProvider(RelationInterface $relation): bool
    {
        if ($this->router->isScoped($relation)) {
            return true;
        }

        // Its rendered linkage is a first page, never the whole set the model holds.
        if ($this->linkagePaginator !== null && ($this->linkagePaginator)($relation) !== null) {
            return true;
        }

        $relatedTypes = $relation->relatedTypes();
        $relatedResource = \count($relatedTypes) === 1 ? ($this->resourceFor)($relatedTypes[0]) : null;

        return $this->relationCriteria->isOrdered($relatedResource, $relation)
            || $this->relationCriteria->hasFilterDefaults($relatedResource, $relation);
    }
}
