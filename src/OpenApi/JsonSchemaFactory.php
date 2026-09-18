<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi;

use haddowg\JsonApi\OpenApi\EnumDescriptionMode;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\ProjectedTypes;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource;
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
 * {@see SchemaProjector} — wrapped with the canonical `$schema` dialect keyword and a
 * stable `$id` so the artifact is a valid, addressable schema document on its own.
 *
 * **Specificity vs the OpenAPI document.** The **attributes** are projected identically
 * to the OpenAPI document (the same {@see SchemaProjector::projectResourceObject()}), so
 * they agree exactly — typed, constrained, enum-narrowed. The **relationships**, **links**
 * and **meta** are deliberately the projector's permissive `{type: object}` placeholders:
 * the OpenAPI document narrows those by `$ref`-ing shared/per-relation components
 * ({@see \haddowg\JsonApi\OpenApi\OpenApiProjector} — the typed relationship objects,
 * `Links`/`Meta`), and a self-contained document has no `components` to reference. So for
 * relationships/links/meta the OpenAPI document is the fuller contract; the standalone
 * document is authoritative for a type's attribute shape. (Inlining the per-relation
 * linkage into the standalone form is a possible future enhancement.)
 *
 * Standalone export emits backed-enum schemas **inline** (no
 * {@see \haddowg\JsonApi\OpenApi\EnumComponentCollector} — a standalone file has no
 * `components` to hoist into), matching the projector's standalone-projection
 * contract. A resource-less / bare-serializer type contributes a permissive
 * resource-object schema (no field inventory), so every type yields a document.
 *
 * **Which types.** The key set is core's {@see ProjectedTypes::forServer()} — the same
 * rule the OpenAPI document describes a resource object for. Reading it off the route
 * descriptors instead is what let the document carry a `UsersResource` component while
 * `schemas.json` had no `users` key. One rule, two artifacts.
 */
final class JsonSchemaFactory
{
    private const DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    private readonly SchemaProjector $schemaProjector;

    /** @var array<string, ServerMetadataInterface> */
    private array $metadataByServer = [];

    public function __construct(
        private readonly ServerProvider $servers,
        private readonly TypeMetadataResolver $types,
        private readonly RouteDescriptorRegistry $descriptors,
        private readonly MetadataSource $metadata,
        EnumDescriptionMode $enumDescriptionMode = EnumDescriptionMode::Both,
    ) {
        $this->schemaProjector = new SchemaProjector($enumDescriptionMode);
    }

    /**
     * The standalone JSON Schema 2020-12 document for one `(server, type)`, as a
     * JSON-ready {@see \stdClass} carrying `$schema` + `$id` (the resource object).
     *
     * @throws \InvalidArgumentException when `$type` is not a type this server's
     *                                   projection describes — a typo
     *                                   (`--type=articals`) fails loudly rather than
     *                                   emitting a bogus generic schema for a
     *                                   non-existent type
     */
    public function forType(string $type, ?string $serverName = null): \stdClass
    {
        $serverName ??= ServerProvider::DEFAULT_SERVER;
        $server = $this->servers->get($serverName);

        if (!\in_array($type, ProjectedTypes::forServer($this->serverMetadata($serverName)), true)) {
            throw new \InvalidArgumentException(\sprintf(
                'Unknown JSON:API type "%s" for server "%s".',
                $type,
                $serverName,
            ));
        }

        // Null for a bare serializer/hydrator pair AND for a related-only type, which
        // registers nothing here at all. Both project fieldless — the permissive
        // resource object the OpenAPI document gives them.
        $resource = $this->types->resourceFor($server, $type);
        $fields = $resource instanceof AbstractResource ? $resource->allFields() : [];

        $document = $this->schemaProjector->projectResourceObject($type, $fields)->toJson();
        // Prepend the dialect + identity keywords so the artifact is a valid,
        // addressable 2020-12 schema document on its own (toJson never sets them —
        // a component schema lives inside an OpenAPI document, this one stands alone).
        $document = (object) (['$schema' => self::DIALECT, '$id' => $this->schemaId($type)] + (array) $document);

        return $document;
    }

    /**
     * The standalone JSON Schema 2020-12 documents for **every** type this server's
     * projection describes, keyed by JSON:API type, in registration order — the
     * directory export form.
     *
     * @return array<string, \stdClass>
     */
    public function forServer(?string $serverName = null): array
    {
        $serverName ??= ServerProvider::DEFAULT_SERVER;

        $documents = [];
        foreach (ProjectedTypes::forServer($this->serverMetadata($serverName)) as $type) {
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

    /**
     * The server's OpenAPI metadata, memoized: {@see forServer()} asks for it once per
     * type otherwise, and building it walks every descriptor.
     */
    private function serverMetadata(string $serverName): ServerMetadataInterface
    {
        return $this->metadataByServer[$serverName] ??= $this->metadata->forServer($serverName);
    }

    private function schemaId(string $type): string
    {
        return \sprintf('urn:jsonapi:schema:%s', $type);
    }
}
