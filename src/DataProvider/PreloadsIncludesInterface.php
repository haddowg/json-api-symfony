<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * An **optional** capability a {@see DataProviderInterface} may also implement to
 * batch eager-load the request's effective `?include` tree (the explicitly
 * requested includes, or a resource's
 * {@see \haddowg\JsonApi\Resource\AbstractResource::getDefaultIncludedRelationships()}
 * fallback when the request sends no `include`), so the included relationships do
 * not N+1 against the store while the response renders them.
 *
 * The {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler} calls this — when
 * the resolved provider implements it — on the primary result entity/entities (a
 * single fetch wrapped as a one-element list) before rendering, so a provider that
 * does not opt in simply renders lazily.
 *
 * The reference {@see Doctrine\IncludePreloader Doctrine implementation} reuses
 * core's include decision ({@see JsonApiRequestInterface::isIncludedRelationship()})
 * so it preloads exactly the tree that is rendered, one batched query per relation
 * per level (Laravel-style; no fetch-joins). Preloading is a pure optimization: a
 * relation the store cannot batch (computed/polymorphic/composite-key) silently
 * falls back to a lazy load, so the response is identical with or without it.
 */
interface PreloadsIncludesInterface
{
    /**
     * Batch-loads the effective include tree of the request rooted at the `$type`
     * resources in `$entities`. A no-op when there is nothing to include, the
     * library is absent, or no relation in the tree is batchable.
     *
     * @param iterable<object> $entities the primary result of `$type` whose includes to preload
     */
    public function preloadIncludes(iterable $entities, string $type, JsonApiRequestInterface $request): void;
}
