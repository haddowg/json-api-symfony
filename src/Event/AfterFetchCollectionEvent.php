<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Event;

use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\DataResponse;

/**
 * Dispatched after a collection is fetched (`GET /{type}`), before it renders.
 * {@see $items} is the materialized page/collection. A subscriber may **replace**
 * the response via {@see setResponse()}; the handler reads the (possibly replaced)
 * {@see response()} back.
 *
 * {@see $paginator} is the paginator that paged this collection, already resolved
 * for the request: a {@see \haddowg\JsonApi\Pagination\MultiPaginator} arrives as
 * the child the request selected, so `kind()` names a concrete strategy such as
 * `page` or `cursor`, never the menu. It is `null` when no pagination ran: a
 * resource whose `pagination()` returns `null` (fetch-all), or a singular filter
 * collapsing the collection.
 */
final class AfterFetchCollectionEvent
{
    private ?DataResponse $response = null;

    /**
     * @param list<object> $items the materialized collection items
     */
    public function __construct(
        public readonly string $type,
        public readonly JsonApiRequestInterface $request,
        public readonly array $items,
        public readonly string $serverName,
        public readonly ?PaginatorInterface $paginator = null,
    ) {}

    public function setResponse(?DataResponse $response): void
    {
        $this->response = $response;
    }

    public function response(): ?DataResponse
    {
        return $this->response;
    }
}
