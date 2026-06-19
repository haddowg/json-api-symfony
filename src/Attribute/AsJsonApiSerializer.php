<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Attribute;

/**
 * Registers the annotated {@see \haddowg\JsonApi\Serializer\SerializerInterface}
 * as the serializer for a JSON:API `type`, **without** an
 * {@see \haddowg\JsonApi\Resource\AbstractResource} (bundle ADR 0024). A type
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
 * cases this type serves, one route emitted per case (bundle ADR 0025). An empty
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
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsJsonApiSerializer
{
    /**
     * @param list<\haddowg\JsonApiBundle\Operation\Operation> $operations the exposed operation allow-list (empty = none)
     * @param string|list<string>|null                         $server     the server name(s) exposing this type (null = the implicit `default`)
     * @param list<string>                                     $tags       the OpenAPI tag names this type is grouped under (empty = the humanized-type default)
     */
    public function __construct(
        public string $type,
        public array $operations = [],
        public string|array|null $server = null,
        public array $tags = [],
    ) {}
}
