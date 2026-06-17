<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\EventListener;

use haddowg\JsonApi\Response\ErrorResponse;

/**
 * An application's seam to map its own domain / third-party exceptions to a
 * JSON:API error document on a JSON:API route, without decorating the whole
 * {@see ExceptionListener}.
 *
 * A service implementing this interface is auto-tagged
 * {@see \haddowg\JsonApiBundle\JsonApiBundle::EXCEPTION_MAPPER_TAG} and consulted
 * by the listener in descending tag `priority` order (default `0`), first
 * non-null result wins. The bundle's own config-driven
 * {@see ConfiguredExceptionMapper} registers at a low priority (`-1000`), so an
 * application mapper is always consulted before the `json_api.exceptions` map.
 *
 * The mappers are consulted **only** for a throwable that is not a core
 * {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface}: a core JSON:API
 * exception always renders natively and is never intercepted or overridden by a
 * mapper (see {@see ExceptionListener::toErrorResponse()}).
 */
interface ExceptionMapperInterface
{
    /**
     * Map a throwable to a JSON:API error response, or null to defer to the next
     * mapper.
     */
    public function map(\Throwable $throwable): ?ErrorResponse;
}
