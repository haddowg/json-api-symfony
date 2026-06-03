<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Operation;

use haddowg\JsonApi\Operation\Target;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds a core {@see Target} from Symfony route defaults and path parameters:
 * the `_jsonapi_type` route default plus the optional `{id}` route attribute
 * (relationship-endpoint resolution lands in a later phase).
 *
 * The {@see \haddowg\JsonApiBundle\EventListener\RequestListener} uses it, and it
 * is the public seam an explicit-route user calls directly — so it is a pure
 * mapper with no container or I/O, trivially unit-testable.
 */
final class TargetResolver
{
    public const string TYPE_ATTRIBUTE = '_jsonapi_type';

    public const string ID_ATTRIBUTE = 'id';

    /**
     * Resolves the target from the request's route attributes, or `null` when the
     * matched route is not a JSON:API route (it carries no `_jsonapi_type`).
     */
    public function resolveFromRequest(Request $request): ?Target
    {
        $type = $request->attributes->get(self::TYPE_ATTRIBUTE);
        if (!\is_string($type) || $type === '') {
            return null;
        }

        $id = $request->attributes->get(self::ID_ATTRIBUTE);

        return new Target($type, \is_string($id) ? $id : null);
    }
}
