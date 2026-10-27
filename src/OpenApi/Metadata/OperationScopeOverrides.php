<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApiBundle\Operation\Operation;

/**
 * Validates the OAuth scope overrides declared on `#[AsJsonApiResource(operationScopes:)]`,
 * `#[AsJsonApiSerializer(operationScopes:)]` and `#[AsJsonApiAction(scopes:)]`, and carries
 * them through the compiled container as JSON, since a nested map is not a flat tag
 * attribute.
 *
 * `operationScopes` is keyed like `operationExamples`. `fetchOne` and `fetchCollection`
 * are accepted even when the type does not expose them, because an `?include` hop reaching
 * the type is checked against them. Core checks each scope's spelling and that a relation
 * key names a relation the type has, when it builds the scope table.
 *
 * @internal
 */
final class OperationScopeOverrides
{
    /**
     * @param array<mixed>    $scopes
     * @param list<Operation> $exposed the CRUD operations the type exposes
     *
     * @throws \LogicException on an unknown key, a write the type doesn't expose, or a value that is not a list of strings
     */
    public static function assertValid(string $attribute, array $scopes, array $exposed): void
    {
        foreach ($scopes as $key => $list) {
            $key = (string) $key;
            OperationKeys::assertExposed($attribute, 'operationScopes', $key, $exposed, [OperationKind::FetchOne, OperationKind::FetchCollection]);
            self::assertList(\sprintf('%s operationScopes["%s"]', $attribute, $key), $list);
        }
    }

    /**
     * @throws \LogicException when `$scopes` is not a list of non-empty strings
     */
    public static function assertList(string $where, mixed $scopes): void
    {
        if (!\is_array($scopes) || !\array_is_list($scopes)) {
            throw new \LogicException(\sprintf('%s must be a list of scopes, got %s.', $where, \get_debug_type($scopes)));
        }

        foreach ($scopes as $scope) {
            if (!\is_string($scope) || $scope === '') {
                throw new \LogicException(\sprintf('%s must list scopes as non-empty strings, got %s.', $where, \get_debug_type($scope)));
            }
        }
    }

    /**
     * The tag value for an `operationScopes` map, or `null` when it is empty.
     *
     * @param array<string, list<string>> $scopes
     */
    public static function encodeMap(array $scopes): ?string
    {
        return $scopes === [] ? null : \json_encode($scopes, \JSON_THROW_ON_ERROR);
    }

    /**
     * The tag value for an action's `scopes`, or `null` to ask the resolver.
     *
     * @param list<string>|null $scopes
     */
    public static function encodeList(?array $scopes): ?string
    {
        return $scopes === null ? null : \json_encode($scopes, \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function decodeMap(mixed $encoded): array
    {
        $decoded = \is_string($encoded) && $encoded !== '' ? \json_decode($encoded, true, 512, \JSON_THROW_ON_ERROR) : null;
        if (!\is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $key => $scopes) {
            $map[(string) $key] = self::strings($scopes);
        }

        return $map;
    }

    /**
     * @return list<string>|null
     */
    public static function decodeList(mixed $encoded): ?array
    {
        if (!\is_string($encoded) || $encoded === '') {
            return null;
        }

        return self::strings(\json_decode($encoded, true, 512, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $scopes): array
    {
        return \is_array($scopes)
            ? \array_values(\array_filter($scopes, static fn(mixed $scope): bool => \is_string($scope)))
            : [];
    }
}
