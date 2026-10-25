<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Server;

use haddowg\JsonApi\Resource\Field\RelationInterface;
use Psr\Container\ContainerInterface;

/**
 * A PSR-11 container over the standalone relations providers (bundle ADR 0026), keyed
 * by server and JSON:API type (bundle ADR 0142). It resolves a type's
 * {@see RelationsProviderInterface} lazily — unlike the {@see ResourceLocator}
 * (keyed by class-string), this is keyed by type because relations are runtime
 * objects, not scalars core can read statically: the {@see TypeMetadataResolver}
 * asks for a type's relations only when it needs them.
 *
 * Each server sees only the providers declared for it, so the relations a server
 * serves for a type, and the per-relation security they carry, are that server's own.
 * A provider on several servers is registered under each.
 *
 * The locator's argument is filled by the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\ResourceLocatorPass}
 * from the services tagged {@see \haddowg\JsonApiBundle\JsonApiBundle::RELATIONS_TAG}.
 */
final class RelationsRegistry
{
    public function __construct(private readonly ContainerInterface $providers) {}

    /**
     * The locator key a provider for `$type` on `$server` is registered under.
     */
    public static function key(string $server, string $type): string
    {
        return $server . "\0" . $type;
    }

    /**
     * The standalone relations declared for `$type` on `$server`, or `null` when no
     * provider is registered for it there (the type either has a resource that owns
     * its relations, or declares none on that server). Without a server it answers
     * with the default server's provider, else the first one declared for the type.
     *
     * @return list<RelationInterface>|null
     */
    public function relationsFor(string $type, ?string $server = null): ?array
    {
        $key = $server !== null ? self::key($server, $type) : $type;
        if (!$this->providers->has($key)) {
            return null;
        }

        $provider = $this->providers->get($key);
        \assert($provider instanceof RelationsProviderInterface);

        return $provider->relations();
    }
}
