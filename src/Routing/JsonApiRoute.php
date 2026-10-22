<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Routing;

use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Operation\TargetResolver;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\HttpFoundation\Request;

/**
 * What a request's matched JSON:API route says about it: the server, the type, and
 * which endpoint of it was hit.
 *
 * Every route {@see JsonApiRouteLoader} emits (resource, relationship, custom action
 * and atomic) carries the {@see self::MARKER} default, and nothing else does. The
 * router copies route defaults into the request attributes, so {@see self::matches()}
 * and {@see self::of()} only answer once routing has run: in a `kernel.request`
 * listener, register below Symfony's `RouterListener` (priority 32).
 *
 * These are facts about the route only. For the request's effective {@see \haddowg\JsonApi\Server\Server}
 * use {@see ServerProvider::forRequest()}; the resource itself is loaded by the
 * lifecycle and reaches hooks and handlers.
 */
final readonly class JsonApiRoute
{
    /**
     * The route default that marks a JSON:API endpoint.
     */
    public const string MARKER = '_jsonapi';

    public function __construct(
        /** The server name, `default` for the bare import. */
        public string $server,
        /** The JSON:API type; `null` on the atomic endpoint. */
        public ?string $type = null,
        /** The `{id}` as the URL carries it; `null` on collection endpoints. */
        public ?string $id = null,
        /** The relationship name on a relationship or related endpoint. */
        public ?string $relationship = null,
        /** `true` for `/relationships/{name}`, `false` for the related endpoint and everything else. */
        public bool $isRelationshipEndpoint = false,
        /** The custom action's path segment; `null` when not an action. */
        public ?string $action = null,
        public ?ActionScope $actionScope = null,
        public bool $isAtomic = false,
    ) {}

    /**
     * Whether the request's matched route is a JSON:API endpoint. `false` before
     * routing, and for any route the bundle didn't emit that doesn't set the marker
     * itself.
     */
    public static function matches(Request $request): bool
    {
        return $request->attributes->get(self::MARKER) === true;
    }

    /**
     * The matched JSON:API route, or `null` when {@see self::matches()} is `false`.
     */
    public static function of(Request $request): ?self
    {
        if (!self::matches($request)) {
            return null;
        }

        $attributes = $request->attributes;
        $server = $attributes->get('_jsonapi_server');
        $type = $attributes->get(TargetResolver::TYPE_ATTRIBUTE);
        $id = $attributes->get(TargetResolver::ID_ATTRIBUTE);
        $relationship = $attributes->get(TargetResolver::RELATIONSHIP_ATTRIBUTE);
        $relationship = \is_string($relationship) && $relationship !== '' ? $relationship : null;
        $action = $attributes->get(JsonApiRouteLoader::ACTION_ATTRIBUTE);
        $scope = $attributes->get(JsonApiRouteLoader::ACTION_SCOPE_ATTRIBUTE);

        return new self(
            server: \is_string($server) && $server !== '' ? $server : ServerProvider::DEFAULT_SERVER,
            type: \is_string($type) && $type !== '' ? $type : null,
            id: \is_string($id) ? $id : null,
            relationship: $relationship,
            isRelationshipEndpoint: $relationship !== null && $attributes->get(TargetResolver::RELATIONSHIP_ENDPOINT_ATTRIBUTE) === true,
            action: \is_string($action) ? $action : null,
            actionScope: \is_string($scope) ? self::scope($scope) : null,
            isAtomic: $attributes->get(JsonApiRouteLoader::ATOMIC_ATTRIBUTE) === true,
        );
    }

    /**
     * Core's {@see Target} for the route, as the lifecycle resolves it; `null` on the
     * atomic endpoint, which addresses no single type.
     */
    public function target(): ?Target
    {
        if ($this->type === null) {
            return null;
        }

        return new Target($this->type, $this->id, $this->relationship, $this->isRelationshipEndpoint);
    }

    private static function scope(string $name): ?ActionScope
    {
        foreach (ActionScope::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        return null;
    }
}
