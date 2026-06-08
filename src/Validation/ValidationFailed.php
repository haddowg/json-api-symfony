<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Validation;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Schema\Error\Error;

/**
 * Thrown when the Symfony Validator bridge rejects a create/update document: a
 * `422 Unprocessable Entity` carrying one {@see Error} per violation, each with a
 * `source.pointer` into the request document.
 *
 * Core ships no `422` exception, so the bridge supplies this one. It implements
 * the core {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} (via
 * {@see AbstractJsonApiException}) so the route-scoped `kernel.exception` listener
 * renders it like any other typed core exception — and core's `ErrorResponse`
 * now honours the declared `422` even for a multi-violation bag (it no longer
 * rounds a uniform set down to `400`).
 */
final class ValidationFailed extends AbstractJsonApiException
{
    /**
     * @param list<Error> $errors one error per violation, each with a source pointer
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('The request document failed validation.', 422);
    }

    /**
     * @return list<Error>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
