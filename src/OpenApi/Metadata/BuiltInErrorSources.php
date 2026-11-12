<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\Exception\ClassListErrorSource;
use haddowg\JsonApi\Exception\ErrorCatalogSourceInterface;
use haddowg\JsonApi\Exception\ErrorScope;
use haddowg\JsonApi\Exception\ScopedErrorSource;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApiBundle\Atomic\AtomicHrefInvalid;
use haddowg\JsonApiBundle\Atomic\AtomicHrefUnresolvable;
use haddowg\JsonApiBundle\Atomic\AtomicOperationNotExposed;
use haddowg\JsonApiBundle\Atomic\AtomicOperationsNotSupported;
use haddowg\JsonApiBundle\Atomic\AtomicOperationsUnavailable;
use haddowg\JsonApiBundle\Atomic\AtomicTargetTypeUnknown;
use haddowg\JsonApiBundle\DataPersister\AsyncWriteNotAllowedInAtomicOperation;
use haddowg\JsonApiBundle\Validation\ValidationFailed;

/**
 * The bundle's own described errors, which every server catalogues ahead of the
 * application's. The Laravel package catalogues the same codes in the same order, so
 * the two adapters project identical documents.
 *
 * `VALIDATION_FAILED` is scoped per server: the writes that read a document, the batch,
 * and the actions of this server that take a document. An action reading no document
 * never validates one.
 */
final class BuiltInErrorSources
{
    /**
     * @param list<TypeMetadataInterface> $types the server's types
     *
     * @return list<ErrorCatalogSourceInterface>
     */
    public static function for(array $types): array
    {
        return [
            new ClassListErrorSource(
                AsyncWriteNotAllowedInAtomicOperation::class,
                AtomicHrefInvalid::class,
                AtomicHrefUnresolvable::class,
                AtomicOperationNotExposed::class,
                AtomicOperationsNotSupported::class,
                AtomicOperationsUnavailable::class,
                AtomicTargetTypeUnknown::class,
                ValidationFailed::class,
            ),
            new ScopedErrorSource(self::validationScope($types), ValidationFailed::class),
        ];
    }

    /**
     * @param list<TypeMetadataInterface> $types
     */
    private static function validationScope(array $types): ErrorScope
    {
        $scope = ErrorScope::kinds(
            OperationKind::Create,
            OperationKind::Update,
            OperationKind::UpdateRelationship,
            OperationKind::AddRelationship,
            OperationKind::RemoveRelationship,
            OperationKind::Atomic,
        );

        foreach ($types as $type) {
            $operations = [];
            foreach ($type->actions() as $action) {
                if ($action->inputMode() !== ActionInputMode::Document) {
                    continue;
                }
                foreach ($action->methods() as $method) {
                    $operations[] = 'action.' . $action->path() . '.' . \strtolower($method);
                }
            }
            if ($operations !== []) {
                $scope = $scope->and(ErrorScope::on($type->type(), ...$operations));
            }
        }

        return $scope;
    }
}
