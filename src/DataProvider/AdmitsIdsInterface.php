<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * A provider that can say, in one id-only probe, which of a set of ids its scope
 * admits, without loading the rows. The bundle narrows the stored pairs of a
 * `MorphToMany` declared with `identifiedBy()` through it before it cuts a page or
 * takes a count, then loads only the page's rows. A provider that doesn't implement it
 * is asked through {@see FetchesByIdsInterface::fetchByIds()} when it can, and
 * otherwise through {@see DataProviderInterface::fetchOne()}, one id at a time.
 */
interface AdmitsIdsInterface
{
    /**
     * The ids among `$ids`, wire ids of `$type`, whose rows this provider's scope
     * admits, in any order. An id no row holds is left out.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public function admitIds(string $type, array $ids, JsonApiRequestInterface $request): array;
}
