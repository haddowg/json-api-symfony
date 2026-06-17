<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ExceptionMapping;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Schema\Error\Error;

/**
 * A core {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} the
 * throwing-resource hook raises for `?throwSignal=jsonapi`. It carries its own status
 * (`418`) and a stable `code` so the invariant test (bundle ADR 0073) can assert
 * the {@see \haddowg\JsonApiBundle\EventListener\ExceptionListener} renders it
 * **natively** — never intercepted or overridden by the config map or a tagged
 * mapper — even though the kernel also registers a mapper that *would* match it
 * (the listener never consults a mapper for a core exception).
 */
final class NativeJsonApiException extends AbstractJsonApiException
{
    public function __construct()
    {
        parent::__construct('A native JSON:API exception', 418);
    }

    /**
     * @return list<Error>
     */
    public function getErrors(): array
    {
        return [
            new Error(
                status: '418',
                code: 'NATIVE_TEAPOT',
                title: 'A native JSON:API exception',
            ),
        ];
    }
}
