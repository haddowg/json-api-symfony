<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi;

use haddowg\JsonApi\OpenApi\EnumDescriptionMode;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApiBundle\Server\RouteDescriptorRegistry;
use haddowg\JsonApiBundle\Server\ServerProvider;
use haddowg\JsonApiBundle\Server\TypeMetadataResolver;

/**
 * Builds the **standalone per-type JSON Schema 2020-12** documents for a server
 * (design §3, D11) — the `json-api:json-schema:export` command's source.
 *
 * Distinct from the {@see DocumentFactory}: that builds the whole OpenAPI document
 * (paths + envelopes + the component set, where a type's schema is `$ref`-able). This
 * builds a *self-contained* JSON Schema 2020-12 document per type — the type's
 * **resource object** (`type` const, `id`, `attributes`, …) projected by core's
 * {@see SchemaProjector} (the same projector the OpenAPI document uses, so the two
 * agree), wrapped with the canonical `$schema` dialect keyword and a stable `$id` so
 * the artifact is a valid, addressable schema document on its own.
 *
 * Standalone export emits backed-enum schemas **inline** (no
 * {@see \haddowg\JsonApi\OpenApi\EnumComponentCollector} — a standalone file has no
 * `components` to hoist into), matching the projector's standalone-projection
 * contract. A resource-less / bare-serializer type contributes a permissive
 * resource-object schema (no field inventory), so every registered type yields a
 * document.
 */
final class JsonSchemaFactory
{
    private const DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    private readonly SchemaProjector $schemaProjector;

    public function __construct(
        private readonly ServerProvider $servers,
        private readonly TypeMetadataResolver $types,
        private readonly RouteDescriptorRegistry $descriptors,
        EnumDescriptionMode $enumDescriptionMode = EnumDescriptionMode::Both,
    ) {
        $this->schemaProjector = new SchemaProjector($enumDescriptionMode);
    }

    /**
     * The standalone JSON Schema 2020-12 document for one `(server, type)`, as a
     * JSON-ready {@see \stdClass} carrying `$schema` + `$id` (the resource object).
     *
     * @throws \InvalidArgumentException when `$type` is not a registered JSON:API type
     *                                   for `$serverName` — a typo (`--type=articals`)
     *                                   fails loudly rather than emitting a bogus
     *                                   generic schema for a non-existent type
     */
    public function forType(string $type, ?string $serverName = null): \stdClass
    {
        $serverName ??= ServerProvider::DEFAULT_SERVER;
        $server = $this->servers->get($serverName);

        // The type must be registered for the server: resourceFor() returns null both
        // for a registered resource-less type AND for a wholly unknown type, so it
        // cannot tell them apart — the descriptor registry is the authoritative
        // "is this type registered" signal (it lists every registered type, resource
        // or standalone).
        if ($this->descriptors->forType($serverName, $type) === null) {
            throw new \InvalidArgumentException(\sprintf(
                'Unknown JSON:API type "%s" for server "%s".',
                $type,
                $serverName,
            ));
        }

        $resource = $this->types->resourceFor($server, $type);
        $fields = $resource instanceof AbstractResource ? \array_values($resource->fields()) : [];

        $document = $this->schemaProjector->projectResourceObject($type, $fields)->toJson();
        // Prepend the dialect + identity keywords so the artifact is a valid,
        // addressable 2020-12 schema document on its own (toJson never sets them —
        // a component schema lives inside an OpenAPI document, this one stands alone).
        $document = (object) (['$schema' => self::DIALECT, '$id' => $this->schemaId($type)] + (array) $document);

        return $document;
    }

    /**
     * The standalone JSON Schema 2020-12 documents for **every** type registered for
     * `$serverName`, keyed by JSON:API type, in registration order — the directory
     * export form.
     *
     * @return array<string, \stdClass>
     */
    public function forServer(?string $serverName = null): array
    {
        $serverName ??= ServerProvider::DEFAULT_SERVER;

        $documents = [];
        foreach (\array_keys($this->descriptors->forServer($serverName)) as $type) {
            if ($type === '') {
                continue;
            }

            $documents[$type] = $this->forType($type, $serverName);
        }

        return $documents;
    }

    /**
     * The standalone JSON Schema 2020-12 documents for **every** type across **every**
     * server, keyed by JSON:API type — the combined-mode aggregate (`multi_server:
     * combined`), the schema twin of {@see DocumentFactory::combined()}. Types are
     * unique across servers (the combined document requires it), so the union carries
     * no collision.
     *
     * @return array<string, \stdClass>
     */
    public function combined(): array
    {
        $documents = [];
        foreach ($this->descriptors->serverNames() as $serverName) {
            foreach ($this->forServer($serverName) as $type => $document) {
                $documents[$type] = $document;
            }
        }

        return $documents;
    }

    private function schemaId(string $type): string
    {
        return \sprintf('urn:jsonapi:schema:%s', $type);
    }
}
