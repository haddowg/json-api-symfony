<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi;

use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The URL a server's `describedby` links point into: its OpenAPI document.
 *
 * A configured `json_api.openapi.document_url` is used as given, with `{server}`
 * replaced by the server name, so a document published elsewhere (a docs site, a CDN
 * serving the `public_path` files) can be named absolutely. Otherwise it is the path of
 * the bundle's own document route for the server, host-relative and including any
 * prefix the routes are imported under. Null when `describedby` is off, generation is
 * disabled, or the document route isn't registered (the expose gate is closed), so no
 * link points at a document nobody serves.
 */
final readonly class DocumentUrlProvider
{
    public const string SERVER_PLACEHOLDER = '{server}';

    /**
     * @param bool    $enabled       `json_api.openapi.enabled` and `json_api.openapi.describedby` both on
     * @param bool    $combined      `json_api.openapi.multi_server === combined`, where every server shares the one document
     * @param ?string $configuredUrl `json_api.openapi.document_url`
     */
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private bool $enabled = true,
        private bool $combined = false,
        private ?string $configuredUrl = null,
    ) {}

    public function forServer(?string $server = null): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $server ??= ServerProvider::DEFAULT_SERVER;
        $name = $this->combined ? 'combined' : $server;

        if ($this->configuredUrl !== null) {
            return \str_replace(self::SERVER_PLACEHOLDER, $name, $this->configuredUrl);
        }

        try {
            if (!$this->combined && $server !== ServerProvider::DEFAULT_SERVER) {
                return $this->urlGenerator->generate('jsonapi.openapi.server', ['server' => $server]);
            }

            return $this->urlGenerator->generate('jsonapi.openapi.default');
        } catch (RoutingException) {
            return null;
        }
    }
}
