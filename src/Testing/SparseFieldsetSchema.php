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

            foreach (['attributes', 'relationships'] as $member) {
                $schema = self::resolve($schemas, $properties->{$member} ?? null);
                if ($schema !== null) {
                    self::keepRequired($schema, static fn(string $name): bool => \in_array($name, $members, true));
                }
            }
        }

        return $copy;
    }

    /**
     * The schema object behind `$schema`, following one local component `$ref`, bare or
     * wrapped in the single-entry `allOf` the generator writes when the reference carries
     * an `example` or `default`.
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
