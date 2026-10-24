<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Attribute;

use haddowg\JsonApiBundle\Operation\Operation;

/**
 * Validates the `extensions` / `operationExtensions` declared on
 * {@see AsJsonApiResource} and {@see AsJsonApiSerializer} (bundle ADR 0127). Core's
 * withers would quietly prefix a bare name, but on a declaration a missing `x-` is
 * more likely a typo than shorthand, so the attributes insist on the full name.
 *
 * @internal
 */
final class VendorExtensions
{
    /**
     * @param string               $attribute           the declaring attribute's short name, for the error message
     * @param array<mixed>         $extensions          the type-wide extensions
     * @param array<mixed>         $operationExtensions the per-operation extensions, keyed by {@see Operation} case name
     * @param list<Operation>      $exposed             the operations the type exposes
     *
     * @throws \LogicException on an invalid name, an unknown operation key or an operation the type doesn't expose
     */
    public static function assertValid(string $attribute, array $extensions, array $operationExtensions, array $exposed): void
    {
        self::assertNames($attribute, $extensions, 'extensions');

        foreach ($operationExtensions as $key => $set) {
            $operation = \is_string($key) ? Operation::tryFrom($key) : null;
            if ($operation === null) {
                throw new \LogicException(\sprintf(
                    '%s operationExtensions has an unknown operation key "%s"; keys must be a %s case name: %s.',
                    $attribute,
                    (string) $key,
                    Operation::class,
                    \implode(', ', \array_map(static fn(Operation $op): string => $op->name, Operation::cases())),
                ));
            }
            if (!\in_array($operation, $exposed, true)) {
                throw new \LogicException(\sprintf(
                    '%s operationExtensions["%s"] targets an operation this type does not expose (%s); '
                    . 'add it to the type\'s operations or drop the entry.',
                    $attribute,
                    $key,
                    $exposed === [] ? 'it exposes none' : 'it exposes ' . \implode(', ', \array_map(static fn(Operation $op): string => $op->name, $exposed)),
                ));
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
