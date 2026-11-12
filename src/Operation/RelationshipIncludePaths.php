<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Operation;

use haddowg\JsonApi\Exception\InclusionDepthExceeded;
use haddowg\JsonApi\Exception\InclusionNotAllowed;
use haddowg\JsonApi\Exception\InclusionUnrecognized;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Transformer\IncludePathGuard;

/**
 * Checks `?include` on a relationship endpoint before anything loads, the way core checks
 * it on every other read.
 *
 * The paths start at the parent resource, as in the spec's own
 * `GET /articles/1/relationships/comments?include=comments.author`. The document stays
 * linkage-only, so a known path changes nothing, but a path naming a relationship the tree
 * does not have is a `400` whatever segment it fails at. Core's dispatch does not run this
 * check for a relationship read, so the handler does.
 */
final class RelationshipIncludePaths
{
    /**
     * @throws InclusionNotAllowed
     * @throws InclusionDepthExceeded
     * @throws InclusionUnrecognized
     */
    public static function validate(Server $server, JsonApiRequestInterface $request, string $type): void
    {
        $paths = $request->getIncludePaths();
        if ($paths === [] || !$server->hasSerializerFor($type)) {
            return;
        }

        IncludePathGuard::rootSafeguards($paths, $server->serializerFor($type), $server->maxIncludeDepth());
        IncludePathGuard::validateTree($request, [$type], $server);
    }
}
