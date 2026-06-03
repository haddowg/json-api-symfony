<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Server;

use haddowg\JsonApi\Resource\AbstractResource;
use Psr\Container\ContainerInterface;

/**
 * A PSR-11 container over the discovered Resource services, keyed by their
 * class-string. It is the resolver core's {@see \haddowg\JsonApi\Server\Server::withContainer()}
 * consumes: the {@see \haddowg\JsonApi\Server\ResourceRegistry} reads each
 * Resource's `static $type` without instantiating, then asks this locator for the
 * instance on first lookup — so a Resource may be a Symfony service with real
 * constructor dependencies.
 *
 * It also exposes the discovered {@see classes()} so the
 * {@see ServerFactory} can register each type, and double-checks that every
 * looked-up service really is an {@see AbstractResource}.
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

    public function get(string $id): AbstractResource
    {
        $instance = $this->services->get($id);

        if (!$instance instanceof AbstractResource) {
            throw new \LogicException(\sprintf(
                'The JSON:API resource locator returned %s for "%s", which is not a %s.',
                \get_debug_type($instance),
                $id,
                AbstractResource::class,
            ));
        }

        return $instance;
    }

    public function has(string $id): bool
    {
        return $this->services->has($id);
    }
}
