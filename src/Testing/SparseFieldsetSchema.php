<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Testing;

use haddowg\JsonApi\OpenApi\ComponentNaming;

/**
 * Relaxes a generated OpenAPI document so it describes a **sparse-fieldset** response.
 *
 * The read schemas list every guaranteed member under `required`, which is right for a
 * full response. A response to `?fields[<type>]=a,b` carries only `a` and `b` for that
 * type, so for each type with a fieldset this drops from `required` every attribute and
 * relationship the fieldset leaves out, and stops the resource object requiring its
 * `attributes` / `relationships` members at all (a fieldset can omit either entirely).
 * Members inside the fieldset stay required, and a type without a fieldset is untouched.
 *
 * A `<attribute>.<key>` member selects keys of an attribute, as the Field Selection
 * profile does at runtime: the attribute stays required, and its schema drops its
 * `required` keys and admits only the selected ones. A longer member such as
 * `<attribute>.<key>.<key>` narrows the nested object's schema the same way, and
 * `<attribute>.*.<key>` narrows the `items` of a list's schema. Listing the attribute, a
 * key, or a list's items as `<attribute>.*`, whole as well keeps its schema whole.
 *
 * The patch targets the type's `<Base>Resource` component, so primary data and
 * `included` resources (which `$ref` the same component) follow their type's fieldset.
 *
 * @internal used by {@see SchemaConformanceTrait}
 */
final class SparseFieldsetSchema
{
    private const string SCHEMA_PREFIX = '#/components/schemas/';

    /**
     * Normalizes a `fields` query map (`['albums' => 'title,year']`, or lists of names)
     * to member-name lists keyed by type.
     *
     * @param array<mixed> $fieldsets
     *
     * @return array<string, list<string>>
     */
    public static function normalize(array $fieldsets): array
    {
        $normalized = [];
        foreach ($fieldsets as $type => $members) {
            if (!\is_string($type)) {
                continue;
            }
            if (\is_string($members)) {
                $members = \explode(',', $members);
            }
            if (!\is_array($members)) {
                continue;
            }

            $names = [];
            foreach ($members as $member) {
                if (\is_string($member) && ($member = \trim($member)) !== '') {
                    $names[] = $member;
                }
            }
            $normalized[$type] = $names;
        }
        \ksort($normalized);

        return $normalized;
    }

    /**
     * Returns a relaxed copy of `$document`; the original is not modified.
     *
     * @param array<string, list<string>> $fieldsets normalized by {@see normalize()}
     */
    public static function relax(\stdClass $document, array $fieldsets): \stdClass
    {
        if ($fieldsets === []) {
            return $document;
        }

        $copy = \json_decode(\json_encode($document, \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR);
        \assert($copy instanceof \stdClass);

        $schemas = $copy->components->schemas ?? null;
        if (!$schemas instanceof \stdClass) {
            return $copy;
        }

        foreach ($fieldsets as $type => $members) {
            $resource = $schemas->{ComponentNaming::base($type) . 'Resource'} ?? null;
            if (!$resource instanceof \stdClass) {
                continue;
            }

            self::keepRequired($resource, static fn(string $name): bool => $name !== 'attributes' && $name !== 'relationships');

            $properties = $resource->properties ?? null;
            if (!$properties instanceof \stdClass) {
                continue;
            }

            [$whole, $selections] = self::split($members);
            $kept = static fn(string $name): bool => \in_array($name, $whole, true) || isset($selections[$name]);

            foreach (['attributes', 'relationships'] as $member) {
                $schema = self::resolve($schemas, $properties->{$member} ?? null);
                if ($schema === null) {
                    continue;
                }
                self::keepRequired($schema, $kept);

                $fields = $schema->properties ?? null;
                if ($member !== 'attributes' || !$fields instanceof \stdClass) {
                    continue;
                }
                foreach ($selections as $name => $keys) {
                    if (isset($fields->{$name})) {
                        $fields->{$name} = self::narrow($schemas, $fields->{$name}, $keys);
                    }
                }
            }
        }

        return $copy;
    }

    /**
     * Splits a fieldset into the fields it lists whole and the keys it selects per
     * attribute, the attribute name up to the first `.` and the rest of the member after
     * it. An attribute also listed whole is not narrowed.
     *
     * @param list<string> $members
     *
     * @return array{list<string>, array<string, list<string>>}
     */
    private static function split(array $members): array
    {
        $whole = [];
        $selections = [];
        foreach ($members as $member) {
            $dot = \strpos($member, '.');
            if ($dot === false) {
                $whole[] = $member;
            } else {
                $selections[\substr($member, 0, $dot)][] = \substr($member, $dot + 1);
            }
        }

        return [$whole, \array_diff_key($selections, \array_flip($whole))];
    }

    /**
     * A copy of an attribute's schema narrowed to `$keys`: an object schema keeps only
     * the selected declared properties, requires none, and admits no other member name.
     * A dotted key is a path, so the property it starts with is narrowed in turn to the
     * rest of it, unless the key is also selected whole. A list schema selected through
     * `*` narrows its `items` to the rest of each path, unless `*` is also selected whole.
     * A local `$ref` is inlined so a shared component stays whole for other uses, and each
     * branch of an `allOf`, `anyOf` or `oneOf` is narrowed in turn.
     *
     * @param list<string> $keys
     */
    private static function narrow(\stdClass $schemas, mixed $schema, array $keys, int $depth = 0): mixed
    {
        if (!$schema instanceof \stdClass || $depth > 8) {
            return $schema;
        }

        $copy = clone $schema;
        $ref = $copy->{'$ref'} ?? null;
        if (\is_string($ref) && \str_starts_with($ref, self::SCHEMA_PREFIX)) {
            $target = $schemas->{\substr($ref, \strlen(self::SCHEMA_PREFIX))} ?? null;
            if ($target instanceof \stdClass) {
                unset($copy->{'$ref'});
                $target = self::narrow($schemas, $target, $keys, $depth + 1);
                \assert($target instanceof \stdClass);
                foreach (\get_object_vars($target) as $keyword => $value) {
                    if (!\property_exists($copy, $keyword)) {
                        $copy->{$keyword} = $value;
                    }
                }
            }
        }

        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            if (isset($copy->{$keyword}) && \is_array($copy->{$keyword})) {
                $copy->{$keyword} = \array_map(static fn(mixed $branch): mixed => self::narrow($schemas, $branch, $keys, $depth + 1), $copy->{$keyword});
            }
        }

        $type = $copy->type ?? null;
        if ($type === 'array' || (\is_array($type) && \in_array('array', $type, true)) || isset($copy->items)) {
            [$whole, $deeper] = self::split($keys);
            if (!\in_array('*', $whole, true) && isset($deeper['*'])) {
                $copy->items = self::narrow($schemas, $copy->items ?? null, $deeper['*'], $depth + 1);
            }

            return $copy;
        }

        $isObject = $type === 'object' || (\is_array($type) && \in_array('object', $type, true))
            || isset($copy->properties) || isset($copy->required) || isset($copy->additionalProperties);
        if (!$isObject) {
            return $copy;
        }

        [, $deeper] = self::split($keys);
        $selected = \array_values(\array_unique(\array_map(static fn(string $key): string => \explode('.', $key, 2)[0], $keys)));

        unset($copy->required, $copy->minProperties);
        if (isset($copy->properties) && $copy->properties instanceof \stdClass) {
            $properties = new \stdClass();
            foreach (\get_object_vars($copy->properties) as $name => $property) {
                $name = (string) $name;
                if (isset($deeper[$name])) {
                    $properties->{$name} = self::narrow($schemas, $property, $deeper[$name], $depth + 1);
                } elseif (\in_array($name, $selected, true)) {
                    $properties->{$name} = $property;
                }
            }
            $copy->properties = $properties;
        }
        $copy->propertyNames = (object) ['enum' => $selected];

        return $copy;
    }

    /**
     * The schema object behind `$schema`, following one local component `$ref`, bare or
     * wrapped in the single-entry `allOf` the generator writes when the reference carries
     * a `type`, `example` or `default`.
     */
    private static function resolve(\stdClass $schemas, mixed $schema): ?\stdClass
    {
        if (!$schema instanceof \stdClass) {
            return null;
        }

        $allOf = $schema->allOf ?? null;
        if (!isset($schema->{'$ref'}) && \is_array($allOf) && \count($allOf) === 1 && $allOf[0] instanceof \stdClass) {
            $schema = $allOf[0];
        }

        $ref = $schema->{'$ref'} ?? null;
        if (\is_string($ref) && \str_starts_with($ref, self::SCHEMA_PREFIX)) {
            $target = $schemas->{\substr($ref, \strlen(self::SCHEMA_PREFIX))} ?? null;

            return $target instanceof \stdClass ? $target : null;
        }

        return $schema;
    }

    /**
     * @param \Closure(string): bool $keep
     */
    private static function keepRequired(\stdClass $schema, \Closure $keep): void
    {
        if (!isset($schema->required) || !\is_array($schema->required)) {
            return;
        }

        $required = \array_values(\array_filter(
            $schema->required,
            static fn(mixed $name): bool => !\is_string($name) || $keep($name),
        ));

        if ($required === []) {
            unset($schema->required);
        } else {
            $schema->required = $required;
        }
    }
}
