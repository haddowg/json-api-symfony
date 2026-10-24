<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\EventListener;

use haddowg\JsonApiBundle\Operation\TargetResolver;
use haddowg\JsonApiBundle\Routing\JsonApiRoute;
use haddowg\JsonApiBundle\Server\ServerProvider;
use haddowg\JsonApiBundle\Server\TypeMetadataResolver;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Rewrites the `relationship` route attribute of a related or relationship endpoint
 * from the URL segment to the relation's name, right after routing, so every later
 * reader ({@see TargetResolver}, {@see JsonApiRoute::of()}, the lifecycle) sees the
 * name a relation is declared under.
 *
 * The segment is the relation's URI field name, or its name for a client still on
 * the old URL (bundle ADR 0133). A segment no relation answers to is left as it is,
 * and the handler answers `404` for it as before.
 */
final class RelationshipSegmentListener
{
    public function __construct(
        private readonly ServerProvider $servers,
        private readonly TypeMetadataResolver $types,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!JsonApiRoute::matches($request)) {
            return;
        }

        $type = $request->attributes->get(TargetResolver::TYPE_ATTRIBUTE);
        $segment = $request->attributes->get(TargetResolver::RELATIONSHIP_ATTRIBUTE);
        if (!\is_string($type) || $type === '' || !\is_string($segment) || $segment === '') {
            return;
        }

        $relation = $this->types->relationAtUriSegment($this->servers->get(JsonApiRoute::of($request)?->server), $type, $segment);
        if ($relation !== null && $relation->name() !== $segment) {
            $request->attributes->set(TargetResolver::RELATIONSHIP_ATTRIBUTE, $relation->name());
        }
    }
}
