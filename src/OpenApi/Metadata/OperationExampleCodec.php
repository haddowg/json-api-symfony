<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\OperationExample;
use haddowg\JsonApiBundle\Operation\Operation;

/**
 * Validates the operation example overrides declared on `#[AsJsonApiResource]`,
 * `#[AsJsonApiSerializer]` and `#[AsJsonApiAction]`, and carries them through the
 * compiled container as JSON, since the container cannot dump an {@see OperationExample}.
 *
 * An override is stored as `false` (no example) or `{request, responses}`. A document
 * decodes back to PHP arrays, so an empty JSON object comes back as `[]`.
 *
 * @internal
 */
final class OperationExampleCodec
{
    /** The core operation name for each bundle {@see Operation} case. */
    private const CRUD = [
        'fetchCollection' => Operation::FetchCollection,
        'fetchOne' => Operation::FetchOne,
        'create' => Operation::Create,
        'update' => Operation::Update,
        'delete' => Operation::Delete,
    ];

    private const RELATION_PREFIXES = ['fetchRelated', 'fetchRelationship', 'updateRelationship', 'addRelationship', 'removeRelationship'];

    /**
     * Checks an `operationExamples` map: each key a core operation name the type can
     * expose, each value an {@see OperationExample}, `false` or `null`, each document
     * JSON-encodable. Core still checks that a relation key names a relation the type
     * has, when it projects the document.
     *
     * @param array<mixed>    $examples
     * @param list<Operation> $exposed  the CRUD operations the type exposes
     *
     * @throws \LogicException on an unknown key, an operation the type doesn't expose or a value of another kind
     */
    public static function assertValid(string $attribute, array $examples, array $exposed): void
    {
        foreach ($examples as $key => $example) {
            $key = (string) $key;
            $operation = self::CRUD[$key] ?? null;
            if ($operation === null && !self::isRelationKey($key)) {
                throw new \LogicException(\sprintf(
                    '%s operationExamples has an unknown operation key "%s"; use one of %s, or %s followed by a dot and the relation name.',
                    $attribute,
                    $key,
                    \implode(', ', \array_keys(self::CRUD)),
                    \implode(', ', self::RELATION_PREFIXES),
                ));
            }
            if ($operation !== null && !\in_array($operation, $exposed, true)) {
                throw new \LogicException(\sprintf(
                    '%s operationExamples["%s"] targets an operation this type does not expose; add it to the type\'s operations or drop the entry.',
                    $attribute,
                    $key,
                ));
            }
            if ($example !== null && $example !== false && !$example instanceof OperationExample) {
                throw new \LogicException(\sprintf(
                    '%s operationExamples["%s"] must be an %s, false or null, got %s.',
                    $attribute,
                    $key,
                    OperationExample::class,
                    \get_debug_type($example),
                ));
            }
        }
    }

    /**
     * The tag value for an `operationExamples` map, or `null` when it is empty.
     *
     * @param array<string, OperationExample|false|null> $examples
     */
    public static function encodeMap(string $attribute, array $examples): ?string
    {
        if ($examples === []) {
            return null;
        }

        $encoded = [];
        foreach ($examples as $key => $example) {
            $encoded[$key] = self::toScalar($example);
        }

        return self::json($attribute, $encoded);
    }

    /**
     * The tag value for an action's `example`, or `null` to keep the generated one.
     */
    public static function encodeOne(string $attribute, OperationExample|false|null $example): ?string
    {
        return $example === null ? null : self::json($attribute, self::toScalar($example));
    }

    /**
     * Decodes a JSON tag value into the scalar map the registries store.
     *
     * @return array<string, array{request: mixed, responses: array<int|string, mixed>}|false>
     */
    public static function decodeMap(mixed $encoded): array
    {
        if (!\is_string($encoded) || $encoded === '') {
            return [];
        }

        $decoded = \json_decode($encoded, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $key => $value) {
            $map[(string) $key] = self::scalar($value);
        }

        return $map;
    }

    /**
     * Decodes an action's JSON tag value; `null` when it declares none.
     *
     * @return array{request: mixed, responses: array<int|string, mixed>}|false|null
     */
    public static function decodeOne(mixed $encoded): array|false|null
    {
        if (!\is_string($encoded) || $encoded === '') {
            return null;
        }

        return self::scalar(\json_decode($encoded, true, 512, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array{request?: mixed, responses?: array<int|string, mixed>}|false $scalar
     */
    public static function toExample(array|false $scalar): OperationExample|false
    {
        if ($scalar === false) {
            return false;
        }

        return new OperationExample($scalar['request'] ?? null, $scalar['responses'] ?? []);
    }

    /**
     * @return array{request: mixed, responses: array<int|string, mixed>}|false
     */
    private static function toScalar(OperationExample|false|null $example): array|false
    {
        return $example instanceof OperationExample
            ? ['request' => $example->request, 'responses' => $example->responses]
            : false;
    }

    /**
     * @return array{request: mixed, responses: array<int|string, mixed>}|false
     */
    private static function scalar(mixed $value): array|false
    {
        if (!\is_array($value)) {
            return false;
        }

        return [
            'request' => $value['request'] ?? null,
            'responses' => \is_array($value['responses'] ?? null) ? $value['responses'] : [],
        ];
    }

    private static function json(string $attribute, mixed $value): string
    {
        try {
            return \json_encode($value, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $e) {
            throw new \LogicException(\sprintf('%s declares an operation example that is not JSON-encodable: %s', $attribute, $e->getMessage()), 0, $e);
        }
    }

    private static function isRelationKey(string $key): bool
    {
        $dot = \strpos($key, '.');

        return $dot !== false
            && $dot < \strlen($key) - 1
            && \in_array(\substr($key, 0, $dot), self::RELATION_PREFIXES, true);
    }
}
