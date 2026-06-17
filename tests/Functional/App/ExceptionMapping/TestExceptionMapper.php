<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ExceptionMapping;

use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;
use haddowg\JsonApiBundle\EventListener\ExceptionMapperInterface;

/**
 * The application-side {@see ExceptionMapperInterface} witness (bundle ADR 0073):
 * it maps a {@see MapperMappedException} to a **rich** JSON:API error — a custom
 * status, a stable `code`, a `source.pointer`, and `meta` — proving a mapper can do
 * more than the status-only config map.
 *
 * It deliberately *also* claims it would map a {@see NativeJsonApiException}, but
 * the {@see \haddowg\JsonApiBundle\EventListener\ExceptionListener} never consults a
 * mapper for a core {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface}
 * (that arm is first and is never overridden) — so the invariant test can assert
 * the native exception still renders natively despite this mapper's reach.
 */
final class TestExceptionMapper implements ExceptionMapperInterface
{
    public function map(\Throwable $throwable): ?ErrorResponse
    {
        if ($throwable instanceof NativeJsonApiException) {
            // Never reached: the listener resolves a core JsonApiExceptionInterface
            // natively before consulting any mapper. Mapping it here makes the
            // invariant observable — if the seam ever broke, this 599 would surface.
            return ErrorResponse::fromErrors(new Error(status: '599', title: 'Mapper overrode a native exception'));
        }

        if ($throwable instanceof BothMappedException) {
            // Also named in the kernel's json_api.exceptions config map (at 409).
            // This mapper sits at the default priority 0; the config-driven mapper
            // sits at -1000, so the listener consults this one first and this 423
            // wins — the ordering the seam guarantees.
            return ErrorResponse::fromErrors(new Error(
                status: '423',
                code: 'LOCKED_BY_MAPPER',
                title: 'Mapped by the tagged mapper',
                meta: ['mappedBy' => 'TestExceptionMapper'],
            ));
        }

        if (!$throwable instanceof MapperMappedException) {
            return null; // defer to the next mapper (incl. the config map)
        }

        return ErrorResponse::fromErrors(new Error(
            status: '418',
            code: 'TEAPOT',
            title: 'I am a teapot',
            detail: $throwable->getMessage(),
            source: ErrorSource::fromPointer('/data/attributes/brew'),
            meta: ['mappedBy' => 'TestExceptionMapper'],
        ));
    }
}
