<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Action;

use Psr\Container\ContainerInterface;

/**
 * Resolves an {@see ActionDescriptor} and its {@see ActionHandlerInterface} service
 * by the composite key `(server, type, scope, path)` (bundle ADR 0076).
 *
 * The descriptors arrive as an associative map keyed by that composite key (built by
 * the {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\ResourceLocatorPass}
 * from the tagged action services + their attribute metadata). Each is a **plain
 * scalar array** rather than an {@see ActionDescriptor} value object — value objects
 * (and the {@see ActionScope}/{@see ActionInput} enums they carry) are not dumpable
 * as a compiled container argument, exactly the constraint the
 * {@see \haddowg\JsonApiBundle\Routing\JsonApiRouteLoader}'s plain-scalar route
 * descriptors avoid — so the registry rehydrates the value object on lookup. The
 * handlers are a PSR-11 service-locator keyed by service id — modelled on how the
 * {@see \haddowg\JsonApiBundle\Server\RelationsRegistry} resolves a provider lazily
 * — so a handler with real constructor dependencies is constructed only when its
 * action is actually invoked.
 *
 * @phpstan-type ActionDescriptorArray array{
 *     type: string,
 *     path: string,
 *     methods: list<string>,
 *     scope: string,
 *     input: string,
 *     inputType: string,
 *     outputType: string,
 *     security: ?string,
 *     handlerServiceId: string,
 *     server: string,
 *     tags?: string,
 *     asLink?: bool,
 * }
 */
final class ActionRegistry
{
    /**
     * @param ContainerInterface                     $handlers    a PSR-11 locator of {@see ActionHandlerInterface}, keyed by service id
     * @param array<string, ActionDescriptorArray> $descriptors the scalar action descriptors, keyed by the composite key
     */
    public function __construct(
        private readonly ContainerInterface $handlers,
        private readonly array $descriptors,
    ) {}

    /**
     * The descriptor registered for the composite key, or `null` when no action is
     * declared for that `(server, type, scope, path)` — the {@see ActionInvoker}
     * maps a `null` to a `404`. The stored scalar array is rehydrated into an
     * {@see ActionDescriptor} value object (with its enums reconstructed by name).
     */
    public function descriptorFor(string $server, string $type, ActionScope $scope, string $path): ?ActionDescriptor
    {
        $descriptor = $this->descriptors[self::key($server, $type, $scope, $path)] ?? null;
        if ($descriptor === null) {
            return null;
        }

        return $this->rehydrate($descriptor);
    }

    /**
     * Every action declared for `(server, type)`, in registration order — the
     * enumeration seam the OpenAPI {@see \haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource}
     * walks to describe a type's `-actions` paths (the composite-key
     * {@see descriptorFor()} cannot list, only look up a known key). Each stored
     * scalar array is rehydrated into an {@see ActionDescriptor}.
     *
     * @return list<ActionDescriptor>
     */
    public function forServerType(string $server, string $type): array
    {
        $matches = [];
        foreach ($this->descriptors as $descriptor) {
            if ($descriptor['server'] === $server && $descriptor['type'] === $type) {
                $matches[] = $this->rehydrate($descriptor);
            }
        }

        return $matches;
    }

    /**
     * Rebuilds an {@see ActionDescriptor} value object from its stored scalar array
     * (enums reconstructed by name, the comma-joined `tags` split back into a list).
     *
     * @param ActionDescriptorArray $descriptor
     */
    private function rehydrate(array $descriptor): ActionDescriptor
    {
        return new ActionDescriptor(
            $descriptor['type'],
            $descriptor['path'],
            $descriptor['methods'],
            ActionScope::{$descriptor['scope']},
            ActionInput::{$descriptor['input']},
            $descriptor['inputType'],
            $descriptor['outputType'],
            $descriptor['security'],
            $descriptor['handlerServiceId'],
            $descriptor['server'],
            $this->splitTags($descriptor['tags'] ?? ''),
            $descriptor['asLink'] ?? false,
        );
    }

    /**
     * Splits the comma-joined `tags` scalar back into a deduped list of non-empty
     * tag names (empty string → empty list).
     *
     * @return list<string>
     */
    private function splitTags(string $tags): array
    {
        if ($tags === '') {
            return [];
        }

        $names = [];
        foreach (\explode(',', $tags) as $name) {
            $name = \trim($name);
            if ($name !== '' && !\in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The handler service for a resolved descriptor, pulled lazily from the
     * locator by {@see ActionDescriptor::$handlerServiceId}.
     */
    public function handlerFor(ActionDescriptor $descriptor): ActionHandlerInterface
    {
        $handler = $this->handlers->get($descriptor->handlerServiceId);
        \assert($handler instanceof ActionHandlerInterface);

        return $handler;
    }

    /**
     * The composite map key for `(server, type, scope, path)`. The scope contributes
     * its case name; the segments are joined by a NUL that cannot appear in a server
     * name, a JSON:API type or a URL path segment, so the key is unambiguous.
     */
    public static function key(string $server, string $type, ActionScope $scope, string $path): string
    {
        return $server . "\0" . $type . "\0" . $scope->name . "\0" . $path;
    }
}
