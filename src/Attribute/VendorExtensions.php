<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Attribute;

use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApiBundle\OpenApi\Metadata\OperationKeys;
use haddowg\JsonApiBundle\Operation\Operation;

/**
 * Validates the `extensions` / `operationExtensions` declared on
 * {@see AsJsonApiResource} and {@see AsJsonApiSerializer} (docs/openapi.md), and the
 * `extensions` declared on {@see AsJsonApiAction}. Core's
 * withers would quietly prefix a bare name, but on a declaration a missing `x-` is
 * more likely a typo than shorthand, so the attributes insist on the full name.
 *
 * `operationExtensions` takes two kinds of key: an {@see Operation} case name such as
 * `Delete`, or core's operation name such as `delete` or `fetchRelated.agencies`. Naming
 * one CRUD operation both ways is refused rather than merged, so the declaration reads
 * one way. A relation key is only parsed here, because the relations are not known until
 * the resource is built; core fails the projection for a relation the type does not have.
 *
 * @internal
 */
final class VendorExtensions
{
    private const RELATIONSHIP_MUTATIONS = [OperationKind::UpdateRelationship, OperationKind::AddRelationship, OperationKind::RemoveRelationship];

    /**
     * @param string               $attribute           the declaring attribute's short name, for the error message
     * @param array<mixed>         $extensions          the type-wide extensions
     * @param array<mixed>         $operationExtensions the per-operation extensions, keyed by {@see Operation} case name or core operation name
     * @param list<Operation>      $exposed             the operations the type exposes
     *
     * @throws \LogicException on an invalid name, an unknown operation key, an operation the type doesn't expose or a CRUD operation named both ways
     */
    public static function assertValid(string $attribute, array $extensions, array $operationExtensions, array $exposed): void
    {
        self::assertNames($attribute, $extensions, 'extensions');

        foreach ($operationExtensions as $key => $set) {
            $key = (string) $key;
            $operation = Operation::tryFrom($key);
            if ($operation !== null) {
                self::assertCaseExposed($attribute, $key, $operation, $exposed);
            } else {
                self::assertNamedExposed($attribute, $key, $exposed);
            }
            if (!\is_array($set)) {
                throw new \LogicException(\sprintf(
                    '%s operationExtensions["%s"] must be a map of x- extension names to values.',
                    $attribute,
                    $key,
                ));
            }
            self::assertNames($attribute, $set, \sprintf('operationExtensions["%s"]', $key));
        }

        foreach (\array_keys($operationExtensions) as $key) {
            $operation = Operation::tryFrom((string) $key);
            if ($operation === null) {
                continue;
            }
            $name = OperationKey::crud(OperationType::from($operation->value))->name();
            if (\array_key_exists($name, $operationExtensions)) {
                throw new \LogicException(\sprintf(
                    '%s operationExtensions names the same operation as both "%s" and "%s"; declare it under one key.',
                    $attribute,
                    $key,
                    $name,
                ));
            }
        }
    }

    /**
     * Splits a validated `operationExtensions` map into the sets keyed by {@see Operation}
     * case name and the sets keyed by core operation name.
     *
     * @param array<string, array<string, mixed>> $operationExtensions
     *
     * @return array{crud: array<string, array<string, mixed>>, named: array<string, array<string, mixed>>}
     */
    public static function split(array $operationExtensions): array
    {
        $crud = [];
        $named = [];
        foreach ($operationExtensions as $key => $set) {
            if (Operation::tryFrom((string) $key) !== null) {
                $crud[(string) $key] = $set;
            } else {
                $named[(string) $key] = $set;
            }
        }

        return ['crud' => $crud, 'named' => $named];
    }

    /**
     * @param list<Operation> $exposed
     */
    private static function assertCaseExposed(string $attribute, string $key, Operation $operation, array $exposed): void
    {
        if (!\in_array($operation, $exposed, true)) {
            throw new \LogicException(\sprintf(
                '%s operationExtensions["%s"] targets an operation this type does not expose (%s); '
                . 'add it to the type\'s operations or drop the entry.',
                $attribute,
                $key,
                $exposed === [] ? 'it exposes none' : 'it exposes ' . \implode(', ', \array_map(static fn(Operation $op): string => $op->name, $exposed)),
            ));
        }
    }

    /**
     * @param list<Operation> $exposed
     */
    private static function assertNamedExposed(string $attribute, string $key, array $exposed): void
    {
        if (OperationKey::tryFromName($key) === null) {
            throw new \LogicException(\sprintf(
                '%s operationExtensions has an unknown operation key "%s"; keys must be a %s case name (%s) '
                . 'or a core operation name (%s, or %s followed by a dot and the relation name).',
                $attribute,
                $key,
                Operation::class,
                \implode(', ', \array_map(static fn(Operation $op): string => $op->name, Operation::cases())),
                self::kinds(static fn(OperationKind $kind): bool => $kind->operationType() !== null),
                self::kinds(static fn(OperationKind $kind): bool => $kind->takesRelation()),
            ));
        }

        $operation = OperationKeys::assertExposed($attribute, 'operationExtensions', $key, $exposed);

        // The relationship mutation endpoints are routed only when the type exposes Update.
        if (\in_array($operation->kind, self::RELATIONSHIP_MUTATIONS, true) && !\in_array(Operation::Update, $exposed, true)) {
            throw new \LogicException(\sprintf(
                '%s operationExtensions["%s"] targets a relationship mutation, which a type only exposes '
                . 'with %s in its operations; add it or drop the entry.',
                $attribute,
                $key,
                Operation::Update->name,
            ));
        }
    }

    /**
     * @param \Closure(OperationKind): bool $filter
     */
    private static function kinds(\Closure $filter): string
    {
        return \implode(', ', \array_map(
            static fn(OperationKind $kind): string => $kind->value,
            \array_values(\array_filter(OperationKind::cases(), $filter)),
        ));
    }

    /**
     * @param array<mixed> $extensions
     */
    private static function assertNames(string $attribute, array $extensions, string $parameter): void
    {
        foreach (\array_keys($extensions) as $name) {
            $name = (string) $name;
            if (!\str_starts_with($name, 'x-') || $name === 'x-') {
                throw new \LogicException(\sprintf(
                    '%s %s declares "%s"; an OpenAPI vendor extension name must start with "x-" '
                    . 'followed by a name, e.g. "x-%s".',
                    $attribute,
                    $parameter,
                    $name,
                    $name === 'x-' ? 'feature' : $name,
                ));
            }
            if (\str_starts_with($name, 'x-oai-') || \str_starts_with($name, 'x-oas-')) {
                throw new \LogicException(\sprintf(
                    '%s %s declares "%s"; the "x-oai-" and "x-oas-" prefixes are reserved by the OpenAPI Initiative.',
                    $attribute,
                    $parameter,
                    $name,
                ));
            }
        }
    }
}
