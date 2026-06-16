<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Attribute;

/**
 * Registers the annotated {@see \haddowg\JsonApi\Hydrator\HydratorInterface} as
 * the hydrator for a JSON:API `type`, **without** an
 * {@see \haddowg\JsonApi\Resource\AbstractResource} (bundle ADR 0024) — the
 * decoupled write half that pairs with {@see AsJsonApiSerializer}. A type is
 * writable exactly when a hydrator is registered for it (and a persister is
 * wired); no hydrator means no writes.
 *
 * May sit on its own class or on the same class as the serializer (one class
 * implementing both interfaces can carry both attributes).
 *
 * `server` names the server(s) this type is exposed on: a single server name, a
 * list of names (the same type may join several servers at once), or `null` for
 * the implicit `default` server.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsJsonApiHydrator
{
    /**
     * @param string|list<string>|null $server the server name(s) exposing this type (null = the implicit `default`)
     */
    public function __construct(
        public string $type,
        public string|array|null $server = null,
    ) {}
}
