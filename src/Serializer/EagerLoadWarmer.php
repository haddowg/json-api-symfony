<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Serializer\EagerLoadValidator;
use haddowg\JsonApiBundle\Server\RouteDescriptorRegistry;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Validates every registered resource type's eager-load declaration
 * ({@see \haddowg\JsonApi\Serializer\DeclaresEagerLoadsInterface::eagerLoadRelationshipPaths()}
 * — the dedup set of every `on()` flattened attribute's backing relation chain) at
 * `cache:warmup` (the bundle ADR 0085 fail-loud seam), so an author mistake fails the
 * BUILD — `cache:clear` / deploy — rather than surfacing as a runtime 500 on a user
 * request.
 *
 * It walks every server's registered types (the {@see RouteDescriptorRegistry} is the
 * runtime-readable enumeration of a server's types; the {@see ServerProvider} resolves
 * the {@see \haddowg\JsonApi\Server\Server}, which is the per-server
 * {@see \haddowg\JsonApi\Resource\SerializerResolverInterface} the validator needs for
 * cross-type segment resolution) and hands each type's serializer to a per-server
 * {@see EagerLoadValidator}. The validator throws a developer-facing `\LogicException`
 * on either of two faults, at ANY depth of any (possibly multi-hop) `on()` chain:
 *
 *  - an **unknown segment** — a typo that names no declared relation (the chain would
 *    silently no-op);
 *  - a **to-many segment** — `on()` flattens a scalar from a to-one chain, so a to-many
 *    segment at any depth is not flattenable (use `?include` to materialise a collection
 *    instead). The rule bites every segment / ancestor, not just the leaf.
 *
 * A segment may be `hidden()` (the idiomatic internal association) or visible — both
 * pass, because the chain is to-one. A polymorphic / inventory-less segment whose next
 * type cannot be resolved is left lazy (the walk stops there), never thrown — mirroring
 * the {@see \haddowg\JsonApiBundle\DataProvider\RelatedIncludeBatcher} eager walk. A
 * serializer that declares no eager loads (it does not implement
 * {@see \haddowg\JsonApi\Serializer\DeclaresEagerLoadsInterface} — a bare/standalone
 * serializer) is a no-op, so it is safe to validate every registered type.
 *
 * Unlike the optional OpenAPI {@see \haddowg\JsonApiBundle\OpenApi\DocumentWarmer}, this
 * warmer is **NOT optional** ({@see isOptional()} returns `false`): the whole point is to
 * fail the build on a bad declaration, so its `\LogicException` must propagate out of
 * `cache:warmup` and abort the deploy.
 */
final class EagerLoadWarmer implements CacheWarmerInterface
{
    /**
     * @param list<string> $serverNames the declared server names (`haddowg_json_api.servers`, including the implicit `default`)
     */
    public function __construct(
        private readonly ServerProvider $servers,
        private readonly RouteDescriptorRegistry $descriptors,
        private readonly array $serverNames,
    ) {}

    /**
     * Not optional: a malformed eager-load declaration MUST fail the build, so the
     * validator's `\LogicException` propagates out of `cache:warmup` and aborts the
     * deploy (it is never a runtime 500).
     */
    public function isOptional(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     *
     * @throws \LogicException when a registered type declares an `on()` chain with an
     *                         unknown segment or a to-many segment at any depth
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        foreach ($this->serverNames as $serverName) {
            $server = $this->servers->get($serverName);
            $validator = new EagerLoadValidator($server);

            foreach (\array_keys($this->descriptors->forServer($serverName)) as $type) {
                if ($type === '' || !$server->hasSerializerFor($type)) {
                    continue;
                }

                $validator->validate($type, $server->serializerFor($type));
            }
        }

        // No preloadable class files: the validation is a pure build-time guard.
        return [];
    }
}
