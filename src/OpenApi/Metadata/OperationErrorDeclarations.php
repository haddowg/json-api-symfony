<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApiBundle\Action\ActionDescriptor;
use haddowg\JsonApiBundle\Operation\Operation;

/**
 * Validates the error codes declared on `#[AsJsonApiResource(operationErrors:)]`,
 * `#[AsJsonApiSerializer(operationErrors:)]` and `#[AsJsonApiAction(errors:)]`, and carries
 * them through the compiled container as JSON, since a nested map is not a flat tag
 * attribute.
 *
 * `operationErrors` is keyed like `operationExamples`, plus `*` for every operation of the
 * type and `action.<path>.<method>` for one method of an action mounted on it. A CRUD
 * operation the type does not expose and a relationship mutation on a type without
 * `Update` fail the build; a relation or action name is only parsed here, and core fails
 * the projection for one the type does not have.
 *
 * @internal
 */
final class OperationErrorDeclarations
{
    /** Every operation of the type. */
    public const string EVERY_OPERATION = '*';

    private const string ACTION_KEY = '/^action\.[^.]+\.(get|post|patch|put|delete)$/';

    /**
     * @param array<mixed>    $errors
     * @param list<Operation> $exposed the CRUD operations the type exposes
     *
     * @throws \LogicException on an unknown key, an operation the type doesn't expose, or a value that is not a non-empty list of described error classes
     */
    public static function assertValid(string $attribute, array $errors, array $exposed): void
    {
        foreach ($errors as $key => $classes) {
            $key = (string) $key;
            if ($key !== self::EVERY_OPERATION && \preg_match(self::ACTION_KEY, $key) !== 1) {
                $operation = OperationKeys::assertExposed($attribute, 'operationErrors', $key, $exposed);
                OperationKeys::assertRelationshipMutationExposed($attribute, 'operationErrors', $key, $operation, $exposed);
            }

            $where = \sprintf('%s operationErrors["%s"]', $attribute, $key);
            self::assertList($where, $classes);
            if ($classes === []) {
                throw new \LogicException(\sprintf('%s must name at least one error class; drop the entry instead.', $where));
            }
        }
    }

    /**
     * @throws \LogicException when `$classes` is not a list of classes implementing {@see DescribedErrorInterface}
     */
    public static function assertList(string $where, mixed $classes): void
    {
        if (!\is_array($classes) || !\array_is_list($classes)) {
            throw new \LogicException(\sprintf('%s must be a list of error classes, got %s.', $where, \get_debug_type($classes)));
        }

        foreach ($classes as $class) {
            if (!\is_string($class) || !\is_a($class, DescribedErrorInterface::class, true)) {
                throw new \LogicException(\sprintf(
                    '%s must list classes implementing %s, got %s.',
                    $where,
                    DescribedErrorInterface::class,
                    \is_string($class) ? '"' . $class . '"' : \get_debug_type($class),
                ));
            }
        }
    }

    /**
     * The tag value for an `operationErrors` map, or `null` when it is empty.
     *
     * @param array<string, list<class-string<DescribedErrorInterface>>> $errors
     */
    public static function encodeMap(array $errors): ?string
    {
        return $errors === [] ? null : \json_encode($errors, \JSON_THROW_ON_ERROR);
    }

    /**
     * The tag value for an action's `errors`, or `null` when it is empty.
     *
     * @param list<class-string<DescribedErrorInterface>> $errors
     */
    public static function encodeList(array $errors): ?string
    {
        return $errors === [] ? null : \json_encode($errors, \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, list<class-string<DescribedErrorInterface>>>
     */
    public static function decodeMap(mixed $encoded): array
    {
        $decoded = \is_string($encoded) && $encoded !== '' ? \json_decode($encoded, true, 512, \JSON_THROW_ON_ERROR) : null;
        if (!\is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $key => $classes) {
            $classes = self::classes($classes);
            if ($classes !== []) {
                $map[(string) $key] = $classes;
            }
        }

        return $map;
    }

    /**
     * @return list<class-string<DescribedErrorInterface>>
     */
    public static function decodeList(mixed $encoded): array
    {
        if (!\is_string($encoded) || $encoded === '') {
            return [];
        }

        return self::classes(\json_decode($encoded, true, 512, \JSON_THROW_ON_ERROR));
    }

    /**
     * `$errors` with each action's `errors` merged in under the action's operation name
     * (`action.<path>.<method>`, one entry per method), each list deduplicated.
     *
     * @param array<string, list<class-string<DescribedErrorInterface>>> $errors
     * @param iterable<ActionDescriptor>                                 $actions
     *
     * @return array<string, list<class-string<DescribedErrorInterface>>>
     */
    public static function withActions(array $errors, iterable $actions): array
    {
        foreach ($actions as $action) {
            if ($action->errors === []) {
                continue;
            }
            foreach ($action->methods as $method) {
                $key = 'action.' . $action->path . '.' . \strtolower($method);
                $errors[$key] = \array_values(\array_unique([...$errors[$key] ?? [], ...$action->errors]));
            }
        }

        return $errors;
    }

    /**
     * @return list<class-string<DescribedErrorInterface>>
     */
    private static function classes(mixed $classes): array
    {
        if (!\is_array($classes)) {
            return [];
        }

        $out = [];
        foreach ($classes as $class) {
            if (\is_string($class) && \is_a($class, DescribedErrorInterface::class, true)) {
                $out[] = $class;
            }
        }

        return $out;
    }
}
