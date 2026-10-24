<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DependencyInjection\Compiler;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\OpenApi\Metadata\ResourceDescriptionRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Collects the declarative OpenAPI description overrides a resource declares via
 * `#[AsJsonApiResource(description: …, operationDescriptions: …)]` (bundle ADR 0092)
 * — the extension records the resource-object description as a scalar `description`
 * tag attribute and the per-operation overrides as one JSON-encoded
 * `operation_descriptions` tag attribute on the {@see JsonApiBundle::RESOURCE_TAG} —
 * and assembles a type-keyed `type → {description, operations}` scalar map injected
 * into the {@see ResourceDescriptionRegistry} the
 * {@see \haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource} reads.
 *
 * Descriptions are type-keyed and server-independent, so the `server` tag attribute is
 * irrelevant here (mirroring the {@see ResourceSecurityPass}). The values flow as
 * plain scalars (the container cannot dump value objects); the per-operation map rides
 * as one JSON string because a nested array is not a flat tag attribute. The pass only
 * runs when the registry is defined.
 *
 * The declared OpenAPI vendor extensions (bundle ADR 0127) ride the same way, as one
 * JSON `openapi_extensions` tag attribute, into the registry's `$extensions` argument.
 * A standalone serializer ({@see JsonApiBundle::SERIALIZER_TAG}) can declare them too.
 */
final class ResourceDescriptionPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ResourceDescriptionRegistry::class)) {
            return;
        }

        /** @var array<string, array{description?: string, operations?: array<string, string>}> $descriptions */
        $descriptions = [];
        /** @var array<string, array{type: array<string, mixed>, operations: array<string, array<string, mixed>>}> $extensions */
        $extensions = [];

        foreach ($container->findTaggedServiceIds(JsonApiBundle::RESOURCE_TAG) as $id => $tags) {
            $definition = $container->findDefinition($id);
            $class = $definition->getClass() ?? (\is_string($id) && \class_exists($id) ? $id : null);
            if ($class === null) {
                continue;
            }

            // A resource bears two RESOURCE_TAG tags (AbstractResource
            // autoconfiguration + the attribute callback), so the type and each value
            // are resolved across all tags — the empty autoconfig tag must not erase
            // the attribute's values.
            $type = $this->typeFromTags($tags, $class);
            if ($type === '') {
                continue;
            }

            $description = $this->scalarFromTags($tags, 'description');
            if ($description !== null) {
                $descriptions[$type]['description'] = $description;
            }

            $operations = $this->operationDescriptionsFromTags($tags);
            if ($operations !== []) {
                $descriptions[$type]['operations'] = $operations;
            }

            $declared = $this->extensionsFromTags($tags);
            if ($declared !== null) {
                $extensions[$type] = $declared;
            }
        }

        foreach ($container->findTaggedServiceIds(JsonApiBundle::SERIALIZER_TAG) as $tags) {
            $type = $this->scalarFromTags($tags, 'type');
            $declared = $this->extensionsFromTags($tags);
            if ($type !== null && $declared !== null) {
                $extensions[$type] ??= $declared;
            }
        }

        $container->getDefinition(ResourceDescriptionRegistry::class)
            ->setArgument('$descriptions', $descriptions)
            ->setArgument('$extensions', $extensions);
    }

    /**
     * The JSON:API type for a resource across its tags: the first tag's `type`
     * override, else the `AbstractResource`'s static `$type`.
     *
     * @param array<array<string, mixed>> $tags
     */
    private function typeFromTags(array $tags, string $class): string
    {
        foreach ($tags as $tag) {
            $type = $tag['type'] ?? null;
            if (\is_string($type) && $type !== '') {
                return $type;
            }
        }

        return \is_a($class, AbstractResource::class, true) ? $class::$type : '';
    }

    /**
     * The non-empty string recorded under `$key` across a resource's tags, or `null`.
     *
     * @param array<array<string, mixed>> $tags
     */
    private function scalarFromTags(array $tags, string $key): ?string
    {
        foreach ($tags as $tag) {
            $value = $tag[$key] ?? null;
            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * The per-operation description overrides decoded from the JSON `operation_descriptions`
     * tag attribute, keyed by {@see \haddowg\JsonApiBundle\Operation\Operation} case
     * name (the extension already validated + encoded them); an empty map when none.
     *
     * @param array<array<string, mixed>> $tags
     *
     * @return array<string, string>
     */
    private function operationDescriptionsFromTags(array $tags): array
    {
        $encoded = $this->scalarFromTags($tags, 'operation_descriptions');
        if ($encoded === null) {
            return [];
        }

        $decoded = \json_decode($encoded, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $operation => $value) {
            if (\is_string($operation) && \is_string($value) && $value !== '') {
                $map[$operation] = $value;
            }
        }

        return $map;
    }

    /**
     * The vendor extensions decoded from the JSON `openapi_extensions` tag attribute,
     * or `null` when the resource declares none.
     *
     * @param array<array<string, mixed>> $tags
     *
     * @return array{type: array<string, mixed>, operations: array<string, array<string, mixed>>}|null
     */
    private function extensionsFromTags(array $tags): ?array
    {
        $encoded = $this->scalarFromTags($tags, 'openapi_extensions');
        if ($encoded === null) {
            return null;
        }

        $decoded = \json_decode($encoded, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            return null;
        }

        $type = [];
        foreach (\is_array($decoded['type'] ?? null) ? $decoded['type'] : [] as $name => $value) {
            $type[(string) $name] = $value;
        }

        $operations = [];
        foreach (\is_array($decoded['operations'] ?? null) ? $decoded['operations'] : [] as $operation => $set) {
            if (!\is_array($set)) {
                continue;
            }
            foreach ($set as $name => $value) {
                $operations[(string) $operation][(string) $name] = $value;
            }
        }

        return ['type' => $type, 'operations' => $operations];
    }
}
