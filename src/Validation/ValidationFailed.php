<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Validation;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorDescriptor;
use haddowg\JsonApi\Exception\ErrorFeature;
use haddowg\JsonApi\Exception\ErrorScope;
use haddowg\JsonApi\Exception\ErrorSourceShape;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
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
 *
 * Each violation is built through {@see describe()}, so the catalogued code, status
 * and title are the ones every violation carries. The descriptor's scope names every
 * action; {@see \haddowg\JsonApiBundle\OpenApi\Metadata\BuiltInErrorSources} narrows
 * it to the actions that take a document.
 */
final class ValidationFailed extends AbstractJsonApiException implements DescribedErrorInterface
{
    /**
     * @param list<Error> $errors one error per violation, each with a source pointer
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('The request document failed validation.', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'VALIDATION_FAILED',
            status: 422,
            title: 'Unprocessable Entity',
            source: ErrorSourceShape::Pointer,
            exampleSource: '/data/attributes/title',
            feature: ErrorFeature::Writes,
            description: 'A value in the request document breaks a rule the resource declares, such as a required attribute left out, a string longer than its maximum or a relationship holding too many members. Each error points at one offending member with `source.pointer`. Correct every one of them and send the request again.',
            scope: ErrorScope::kinds(
                OperationKind::Create,
                OperationKind::Update,
                OperationKind::UpdateRelationship,
                OperationKind::AddRelationship,
                OperationKind::RemoveRelationship,
                OperationKind::Action,
                OperationKind::Atomic,
            ),
        );
    }

    /**
     * @return list<Error>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
