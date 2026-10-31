<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * A provider that can say, in one probe, which of a set of rows its scope admits. The
 * bundle narrows a computed (`extractUsing()`) relation's rows through it: the closure picks
 * the candidates and the provider's scope decides which of them
 * exist. A scoped provider that doesn't implement it is asked through
 * {@see DataProviderInterface::fetchOne()}, one row at a time.
 */
interface AdmitsRowsInterface
{
    /**
     * The rows of `$rows`, of type `$type`, that this provider's scope admits, in their
     * given order.
     *
     * @param list<object> $rows
     *
     * @return list<object>
     */
    public function admitRows(string $type, array $rows, JsonApiRequestInterface $request): array;
}
