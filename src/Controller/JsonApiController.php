<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Controller;

use haddowg\JsonApi\Response\AbstractResponse;
use haddowg\JsonApiBundle\EventListener\RequestListener;
use Symfony\Component\HttpFoundation\Request;

/**
 * The controller every auto-generated JSON:API route resolves to. The
 * {@see RequestListener} has already dispatched the operation and stashed the
 * core response value object on the request, so this controller simply returns
 * it — the {@see \haddowg\JsonApiBundle\EventListener\ViewListener} then renders
 * the VO to an HttpFoundation response on `kernel.view`.
 *
 * A controller is needed because Symfony's HttpKernel requires every matched
 * route to resolve to one; keeping it a no-op pass-through preserves the
 * documented request→dispatch→view→render split.
 */
final class JsonApiController
{
    public function __invoke(Request $request): AbstractResponse
    {
        $response = $request->attributes->get(RequestListener::RESPONSE_ATTRIBUTE);

        if (!$response instanceof AbstractResponse) {
            throw new \LogicException('The JSON:API request listener did not produce a response for this route.');
        }

        return $response;
    }
}
