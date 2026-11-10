<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApiBundle\DataProvider\RelatedReadRouter;
use haddowg\JsonApiBundle\DataProvider\RelationCriteriaFactory;

/**
 * The load state a server renders with. A to-many whose members come from its
 * provider's read, because the provider scopes it, a filter default narrows it or it
 * has a default read order, never counts as loaded here. Core asks only when no
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
     * @param \Closure(string): ?AbstractResource $resourceFor the server's resource for a type
     */
    public function __construct(
        private readonly RelationshipLoadStateInterface $inner,
        private readonly RelatedReadRouter $router,
        private readonly \Closure $resourceFor,
        private readonly RelationCriteriaFactory $relationCriteria = new RelationCriteriaFactory(),
    ) {}

    public function isRelationshipLoaded(mixed $model, RelationInterface $relation): bool
    {
        if (!$this->inner->isRelationshipLoaded($model, $relation)) {
            return false;
        }

        return !$relation->isToMany() || !$this->readsThroughProvider($relation);
    }

    /**
     * Whether the model already holds the relation's value, as storage reports it.
     */
    public function isLoadedOnModel(mixed $model, RelationInterface $relation): bool
    {
        return $this->inner->isRelationshipLoaded($model, $relation);
    }

    private function readsThroughProvider(RelationInterface $relation): bool
    {
        if ($this->router->isScoped($relation)) {
            return true;
        }

        $relatedTypes = $relation->relatedTypes();
        $relatedResource = \count($relatedTypes) === 1 ? ($this->resourceFor)($relatedTypes[0]) : null;

        return $this->relationCriteria->isOrdered($relatedResource, $relation)
            || $this->relationCriteria->hasFilterDefaults($relatedResource, $relation);
    }
}
