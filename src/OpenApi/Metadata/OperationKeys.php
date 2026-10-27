<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApiBundle\Operation\Operation;

/**
 * Checks the operation names an attribute keys a per-operation map by, with core's
 * {@see OperationKey} grammar, so `operationExamples` and `operationScopes` accept the
 * same keys.
 *
 * @internal
 */
final class OperationKeys
{
    /**
     * Parses `$key` and refuses a CRUD operation the type does not expose. A relation key
     * is only parsed here; core checks the relation exists when it reads the map.
     *
     * @param list<Operation>     $exposed   the CRUD operations the type exposes
     * @param list<OperationKind> $unchecked the CRUD kinds accepted even when not exposed
     *
     * @throws \LogicException on a key that is not an operation name, or a CRUD operation the type does not expose
     */
    public static function assertExposed(string $attribute, string $member, string $key, array $exposed, array $unchecked = []): OperationKey
    {
        $operation = OperationKey::tryFromName($key);
        if ($operation === null) {
            throw new \LogicException(\sprintf(
                '%s %s has an unknown operation key "%s"; use one of %s, or %s followed by a dot and the relation name.',
                $attribute,
                $member,
                $key,
                self::names(static fn(OperationKind $kind): bool => $kind->operationType() !== null),
                self::names(static fn(OperationKind $kind): bool => $kind->takesRelation()),
            ));
        }

        $crud = $operation->kind->operationType();
        if ($crud !== null
            && !\in_array($operation->kind, $unchecked, true)
            && !\in_array(Operation::from($crud->value), $exposed, true)) {
            throw new \LogicException(\sprintf(
                '%s %s["%s"] targets an operation this type does not expose; add it to the type\'s operations or drop the entry.',
                $attribute,
                $member,
                $key,
            ));
        }

        return $operation;
    }

    /**
     * @param \Closure(OperationKind): bool $filter
     */
    private static function names(\Closure $filter): string
    {
        return \implode(', ', \array_map(
            static fn(OperationKind $kind): string => $kind->value,
            \array_values(\array_filter(OperationKind::cases(), $filter)),
        ));
    }
}
