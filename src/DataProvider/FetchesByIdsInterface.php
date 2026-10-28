<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * A provider that loads many rows of one type by id in one query, applying the scope
 * its related reads apply. The bundle loads the targets of a `MorphTo` declared with
 * `identifiedBy()` through it, one call per target type (bundle ADR 0163). A provider
 * that doesn't implement it is asked through {@see DataProviderInterface::fetchOne()},
 * one id at a time, and is taken to scope every type it serves unless it says
 * otherwise through {@see ScopesRelatedReadsInterface}.
 */
interface FetchesByIdsInterface
{
    /**
     * The rows of `$type` among `$ids` that this provider's scope admits, in any
     * order. Ids are wire ids, as {@see DataProviderInterface::fetchOne()} takes them,
     * and an id no row holds is left out.
     *
     * @param list<string> $ids
     *
     * @return list<object>
     */
    public function fetchByIds(string $type, array $ids, JsonApiRequestInterface $request): array;

    /**
     * Whether {@see fetchByIds()} can leave out a row of `$type` that exists, because a
     * scope hides it. When it can't, a stored pair naming the type renders without a
     * load. The answer is cached per type, so it must not depend on the request.
     */
    public function scopesFetchByIds(string $type): bool;
}
