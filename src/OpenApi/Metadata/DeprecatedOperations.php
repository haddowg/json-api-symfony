<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApiBundle\Operation\Operation;

/**
 * Validates the `deprecatedOperations` list declared on `#[AsJsonApiResource]` and
 * `#[AsJsonApiSerializer]`, and carries it through the compiled container as one
 * comma-joined tag attribute.
 *
 * The list names operations the way `operationExamples` keys them. An unknown name, a
 * CRUD operation the type does not expose and a relationship mutation on a type without
 * `Update` fail the build. A relation name is only parsed here; core fails the
 * projection for a relation the type does not have.
 *
 * @internal
 */
final class DeprecatedOperations
{
    /**
     * @param array<mixed>    $names
     * @param list<Operation> $exposed the CRUD operations the type exposes
     *
     * @throws \LogicException on a value that is not a list of strings, an unknown name, or an operation the type doesn't expose
     */
    public static function assertValid(string $attribute, array $names, array $exposed): void
    {
        if (!\array_is_list($names)) {
            throw new \LogicException(\sprintf('%s deprecatedOperations must be a list of operation names, not a map.', $attribute));
        }

        foreach ($names as $name) {
            if (!\is_string($name)) {
                throw new \LogicException(\sprintf('%s deprecatedOperations must list operation names as strings, got %s.', $attribute, \get_debug_type($name)));
            }

            $operation = OperationKeys::assertExposed($attribute, 'deprecatedOperations', $name, $exposed);
            OperationKeys::assertRelationshipMutationExposed($attribute, 'deprecatedOperations', $name, $operation, $exposed);
        }
    }

    /**
     * The tag value for a `deprecatedOperations` list, or `null` when it is empty.
     *
     * @param list<string> $names
     */
    public static function encode(array $names): ?string
    {
        return $names === [] ? null : \implode(',', \array_values(\array_unique($names)));
    }

    /**
     * @return list<string>
     */
    public static function decode(mixed $encoded): array
    {
        if (!\is_string($encoded) || $encoded === '') {
            return [];
        }

        return \array_values(\array_filter(\explode(',', $encoded), static fn(string $name): bool => $name !== ''));
    }
}
