<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Server;

use haddowg\JsonApi\Resource\Field\RelationInterface;
use Psr\Container\ContainerInterface;

/**
 * A PSR-11 container over the standalone relations providers, keyed by JSON:API
 * **type** (bundle ADR 0026). It resolves a type's
 * {@see RelationsProviderInterface} lazily — unlike the {@see ResourceLocator}
 * (keyed by class-string), this is type-keyed because relations are runtime
 * objects, not scalars core can read statically: the {@see TypeMetadataResolver}
 * asks for a type's relations only when it needs them.
 *
 * The locator's argument is filled by the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\ResourceLocatorPass}
 * from the services tagged {@see \haddowg\JsonApiBundle\JsonApiBundle::RELATIONS_TAG}.
 */
final class RelationsRegistry
{
    public function __construct(private readonly ContainerInterface $providers) {}

    /**
     * The standalone relations declared for `$type`, or `null` when no provider is
     * registered for it (the type either has a resource that owns its relations, or
     * declares none).
     *
     * @return list<RelationInterface>|null
     */
    public function relationsFor(string $type): ?array
    {
        if (!$this->providers->has($type)) {
            return null;
        }

        $provider = $this->providers->get($type);
        \assert($provider instanceof RelationsProviderInterface);

        return $provider->relations();
    }
}
