<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Attribute;

use haddowg\JsonApi\OpenApi\Metadata\OperationExample;
use haddowg\JsonApiBundle\OpenApi\Metadata\OperationExampleCodec;
use haddowg\JsonApiBundle\OpenApi\Metadata\OperationScopeOverrides;

/**
 * Registers the annotated {@see \haddowg\JsonApi\Serializer\SerializerInterface}
 * as the serializer for a JSON:API `type`, **without** an
 * {@see \haddowg\JsonApi\Resource\AbstractResource}. A type
 * registered this way is **serialize-only** by default: it renders as primary
 * data, linkage and `included`, but exposes no endpoints of its own — the classic
 * embedded / reference type that only ever appears inside another resource.
 *
 * `AbstractResource` is the preferred sugar (it supplies serializer + hydrator +
 * relations + the fields DSL from one declaration); this is the decoupled path for
 * a type whose wire shape is fully hand-written, or that has no resource at all.
 * Pair it with {@see AsJsonApiHydrator} (and a provider/persister) to make the
 * type writable / fetchable.
 *
 * `operations` is the exposed operation allow-list: the {@see \haddowg\JsonApiBundle\Operation\Operation}
 * cases this type serves, one route emitted per case. An empty
 * array means the default — for a standalone serializer, no endpoints (serialize-only).
 *
 * `server` names the server(s) this type is exposed on: a single server name, a
 * list of names (the same type may join several servers at once), or `null` for
 * the implicit `default` server.
 *
 * `tags` declares the **OpenAPI tag names** every operation of this standalone type
 * is grouped under in the generated OpenAPI document (design §4.7, D15). An empty
 * array means the default: a single tag named the humanized-type form. Tags carry
 * no JSON:API meaning.
 *
 * `extensions` and `operationExtensions` declare **OpenAPI vendor extensions**, as on
 * {@see AsJsonApiResource} (docs/openapi.md). `extensions` lands on every operation the
 * document emits for the type, including custom actions mounted on it, so a
 * serialize-only type can still mark its actions. `operationExtensions` is keyed by
 * {@see \haddowg\JsonApiBundle\Operation\Operation} case name and may only name an
 * operation in `operations`. The names are validated the same way as on the resource
 * attribute.
 *
 * `operationExamples` replaces or switches off the type's generated OpenAPI operation
 * examples, as on {@see AsJsonApiResource}.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsJsonApiSerializer
{
    /**
     * @param list<\haddowg\JsonApiBundle\Operation\Operation> $operations the exposed operation allow-list (empty = none)
     * @param string|list<string>|null                         $server     the server name(s) exposing this type (null = the implicit `default`)
     * @param list<string>                                     $tags       the OpenAPI tag names this type is grouped under (empty = the humanized-type default)
     * @param array<string, mixed>                             $extensions OpenAPI vendor extensions (`x-…` keys) stamped on every operation of this type
     * @param array<string, array<string, mixed>>              $operationExtensions per-operation OpenAPI vendor extensions, keyed by an exposed {@see \haddowg\JsonApiBundle\Operation\Operation} case name and merged over `extensions`
     * @param array<string, OperationExample|false|null>       $operationExamples per-operation OpenAPI example overrides, as on {@see AsJsonApiResource}
     * @param array<string, list<string>>                      $operationScopes   per-operation OAuth scope overrides, as on {@see AsJsonApiResource}
     */
    public function __construct(
        public string $type,
        public array $operations = [],
        public string|array|null $server = null,
        public array $tags = [],
        public array $extensions = [],
        public array $operationExtensions = [],
        public array $operationExamples = [],
        public array $operationScopes = [],
    ) {
        VendorExtensions::assertValid('AsJsonApiSerializer', $extensions, $operationExtensions, \array_values($operations));
        OperationExampleCodec::assertValid('AsJsonApiSerializer', $operationExamples, \array_values($operations));
        OperationScopeOverrides::assertValid('AsJsonApiSerializer', $operationScopes, \array_values($operations));
    }
}
