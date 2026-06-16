<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Attribute;

/**
 * Declares the relations of a JSON:API `type` independently of an
 * {@see \haddowg\JsonApi\Resource\AbstractResource} (bundle ADR 0026). Goes on a
 * class implementing {@see \haddowg\JsonApiBundle\Server\RelationsProviderInterface},
 * whose {@see \haddowg\JsonApiBundle\Server\RelationsProviderInterface::relations()}
 * returns the type's {@see \haddowg\JsonApi\Resource\Field\RelationInterface} list.
 *
 * `AbstractResource` bundles its relations into the same declaration, so it needs no
 * separate provider; this is the decoupled path that gives a resource-less type
 * (paired with `#[AsJsonApiSerializer]`) working relationship endpoints and
 * relationship rendering — the route loader gates relationship routes on a type
 * having relations (resource or standalone), and {@see \haddowg\JsonApiBundle\Server\TypeMetadataResolver}
 * sources relations resource-first then from the registry.
 *
 * `server` names the server(s) this type is exposed on: a single server name, a
 * list of names (the same type may join several servers at once), or `null` for
 * the implicit `default` server.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsJsonApiRelations
{
    /**
     * @param string|list<string>|null $server the server name(s) exposing this type (null = the implicit `default`)
     */
    public function __construct(
        public string $type,
        public string|array|null $server = null,
    ) {}
}
