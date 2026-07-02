<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\EventListener;

use haddowg\JsonApi\Response\AbstractResponse;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Stamps a top-level `links.describedby` onto every JSON:API response, pointing at the
 * served OpenAPI document for the request's server (JSON:API 1.1 — a link to a
 * description document). It runs on `kernel.view` **before** the {@see ViewListener}
 * (higher priority), re-stashing the response value object with the link set via core's
 * {@see AbstractResponse::withDescribedby()}; the ViewListener then renders it.
 *
 * The link is generated against the request host by the router, so it is correct behind
 * any prefix/host the application mounted the document routes under. It points at the
 * default server's document, or — in per-server mode — the current server's document
 * ({@see \haddowg\JsonApiBundle\Routing\OpenApiRouteLoader} route names). When the
 * document routes are not registered (generation disabled, or the expose gate closed)
 * URL generation fails and no link is added — so the member appears only when the
 * document is actually reachable.
 *
 * Disabled wholesale by `json_api.openapi.describedby: false`.
 */
final class DescribedbyListener
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly bool $enabled,
        private readonly bool $combined,
    ) {}

    public function onKernelView(ViewEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        $request = $event->getRequest();

        $response = $request->attributes->get(RequestListener::RESPONSE_ATTRIBUTE);
        if (!$response instanceof AbstractResponse) {
            return;
        }

        $url = $this->describedbyUrl($request);
        if ($url === null) {
            return;
        }

        $request->attributes->set(
            RequestListener::RESPONSE_ATTRIBUTE,
            $response->withDescribedby(new Link($url)),
        );
    }

    /**
     * The absolute URL of the OpenAPI document for the request's server, or null when
     * the document route is not registered (so no `describedby` is stamped).
     */
    private function describedbyUrl(Request $request): ?string
    {
        $server = $request->attributes->get('_jsonapi_server');

        try {
            // In per-server mode a named (non-default) server has its own document route;
            // the default server and combined mode both serve the single default document.
            if (!$this->combined && \is_string($server) && $server !== '' && $server !== ServerProvider::DEFAULT_SERVER) {
                return $this->urlGenerator->generate(
                    'jsonapi.openapi.server',
                    ['server' => $server],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                );
            }

            return $this->urlGenerator->generate('jsonapi.openapi.default', [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (RoutingException) {
            // The document routes are not registered (generation disabled or the expose
            // gate closed) — the document is unreachable, so advertise no link to it.
            return null;
        }
    }
}
