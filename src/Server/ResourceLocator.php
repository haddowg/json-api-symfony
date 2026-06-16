<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Server;

use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Serializer\SerializerInterface;
use Psr\Container\ContainerInterface;

/**
 * A PSR-11 container over the discovered Resource services, keyed by their
 * class-string. It is the resolver core's {@see \haddowg\JsonApi\Server\Server::withContainer()}
 * consumes: the {@see \haddowg\JsonApi\Server\ResourceRegistry} reads each
 * Resource's `static $type` without instantiating, then asks this locator for the
 * instance on first lookup — so a Resource may be a Symfony service with real
 * constructor dependencies.
 *
 * It also resolves a type's override serializer/hydrator services (ADR 0023) —
 * core asks the same resolver for those class-strings — and exposes the discovered
 * {@see classes()} (the global union of every server's resources). The per-server
 * {@see ServerFactory} registers only its own subset; this locator stays the shared
 * resolver/container core looks every class up through (ADR 0034). Every looked-up
 * service must be a {@see SerializerInterface} or {@see HydratorInterface} (an
 * {@see AbstractResource} is both); anything else is a wiring error.
 */
final class ResourceLocator implements ContainerInterface
{
    /**
     * @param ContainerInterface          $services a service locator keyed by Resource class-string
     * @param list<class-string<AbstractResource>> $classes  the discovered Resource class-strings
     */
    public function __construct(
        private readonly ContainerInterface $services,
        private readonly array $classes,
    ) {}

    /**
     * The discovered Resource class-strings, in registration order.
     *
     * @return list<class-string<AbstractResource>>
     */
    public function classes(): array
    {
        return $this->classes;
    }

    public function get(string $id): object
    {
        $instance = $this->services->get($id);

        if (!$instance instanceof SerializerInterface && !$instance instanceof HydratorInterface) {
            throw new \LogicException(\sprintf(
                'The JSON:API resource locator returned %s for "%s", which is neither a %s nor a %s.',
                \get_debug_type($instance),
                $id,
                SerializerInterface::class,
                HydratorInterface::class,
            ));
        }

        return $instance;
    }

    public function has(string $id): bool
    {
        return $this->services->has($id);
    }
}
