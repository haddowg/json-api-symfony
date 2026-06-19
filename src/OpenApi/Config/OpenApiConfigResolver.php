<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Config;

use haddowg\JsonApi\OpenApi\Contact;
use haddowg\JsonApi\OpenApi\EnumDescriptionMode;
use haddowg\JsonApi\OpenApi\ExternalDocumentation;
use haddowg\JsonApi\OpenApi\License;
use haddowg\JsonApi\OpenApi\OAuthFlow;
use haddowg\JsonApi\OpenApi\OAuthFlows;
use haddowg\JsonApi\OpenApi\SecurityRequirement;
use haddowg\JsonApi\OpenApi\SecurityScheme;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\OpenApi\Tag;
use haddowg\JsonApiBundle\OpenApi\Metadata\ServerDocumentConfig;

/**
 * Resolves the `json_api.openapi.*` configuration array (already normalised by the
 * config tree) into a typed {@see OpenApiConfig} — the compile-time translation of
 * config into the OAS value-object model (design §6, §4.6/§4.7).
 *
 * The info / advertised-servers / security-scheme / tag-definition / external-docs
 * data is document-wide (config carries no per-server openapi override), so the same
 * {@see ServerDocumentConfig} is produced for every declared server — except its
 * default title, which is left null so the {@see \haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource}
 * supplies the per-server fallback (`JSON:API` / `JSON:API (<server>)`), and its
 * advertised `servers`, which stay empty when config declares none so the source
 * derives each server's from its own base URI.
 *
 * It builds plain `final readonly` OAS VOs (no closures), so the resulting
 * {@see OpenApiConfig} is safe to dump as a compiled container argument.
 */
final class OpenApiConfigResolver
{
    /**
     * @param array<string, mixed> $config  the full `json_api` config array
     * @param list<string>         $servers the declared server names
     */
    public function resolve(array $config, array $servers): OpenApiConfig
    {
        $openapi = $config['openapi'] ?? [];
        $openapi = \is_array($openapi) ? $openapi : [];

        $info = \is_array($openapi['info'] ?? null) ? $openapi['info'] : [];

        // Resolve the schemes first so the default requirement can be filtered to only
        // schemes that actually resolved — a requirement naming a dropped/undefined
        // scheme would emit a dangling `security` reference that fails OAS 3.1
        // meta-validation (a secured operation referencing a scheme not in
        // components.securitySchemes).
        $securitySchemes = $this->securitySchemes($openapi);

        $document = new ServerDocumentConfig(
            title: $this->scalarOrNull($info, 'title'),
            version: $this->scalarOrNull($info, 'version'),
            description: $this->scalarOrNull($info, 'description'),
            contact: $this->contact($info),
            license: $this->license($info),
            servers: $this->servers($openapi),
            tagDefinitions: $this->tags($openapi),
            securitySchemes: $securitySchemes,
            defaultSecurity: $this->defaultSecurity($openapi, $securitySchemes),
            externalDocs: $this->externalDocs($openapi['externalDocs'] ?? null),
        );

        $serverDocuments = [];
        foreach ($servers as $server) {
            $serverDocuments[$server] = $document;
        }

        return new OpenApiConfig(
            enabled: ($openapi['enabled'] ?? true) !== false,
            exposeInProd: ($openapi['expose_in_prod'] ?? false) === true,
            combined: ($openapi['multi_server'] ?? 'per_server') === 'combined',
            enumDescriptionMode: EnumDescriptionMode::from(\is_string($openapi['enum_value_descriptions'] ?? null) ? $openapi['enum_value_descriptions'] : 'both'),
            jsonPath: $this->jsonPath($openapi),
            publicPath: $this->scalarOrNull($openapi, 'public_path'),
            ui: $this->ui($openapi),
            serverDocuments: $serverDocuments,
        );
    }

    /**
     * @param array<string, mixed> $openapi
     */
    private function ui(array $openapi): OpenApiUiConfig
    {
        $ui = \is_array($openapi['ui'] ?? null) ? $openapi['ui'] : [];

        $renderer = \is_string($ui['renderer'] ?? null)
            ? (OpenApiUiRenderer::tryFrom($ui['renderer']) ?? OpenApiUiRenderer::Swagger)
            : OpenApiUiRenderer::Swagger;

        $path = $this->scalarOrNull($ui, 'path') ?? '/docs';

        return new OpenApiUiConfig(
            enabled: ($ui['enabled'] ?? true) !== false,
            renderer: $renderer,
            path: '/' . \ltrim($path, '/'),
            cdn: $this->scalarOrNull($ui, 'cdn'),
        );
    }

    /**
     * @param array<string, mixed> $info
     */
    private function contact(array $info): ?Contact
    {
        $contact = \is_array($info['contact'] ?? null) ? $info['contact'] : [];
        $name = $this->scalarOrNull($contact, 'name');
        $url = $this->scalarOrNull($contact, 'url');
        $email = $this->scalarOrNull($contact, 'email');

        return ($name === null && $url === null && $email === null) ? null : new Contact($name, $url, $email);
    }

    /**
     * @param array<string, mixed> $info
     */
    private function license(array $info): ?License
    {
        $license = \is_array($info['license'] ?? null) ? $info['license'] : [];
        $name = $this->scalarOrNull($license, 'name');
        if ($name === null) {
            return null;
        }

        // A license carries identifier XOR url; prefer the SPDX identifier when both
        // are given (it is the more precise OAS 3.1 form).
        $identifier = $this->scalarOrNull($license, 'identifier');
        $url = $this->scalarOrNull($license, 'url');

        return new License($name, $identifier, $identifier !== null ? null : $url);
    }

    /**
     * @param array<string, mixed> $openapi
     *
     * @return list<Server>
     */
    private function servers(array $openapi): array
    {
        $servers = $openapi['servers'] ?? [];
        if (!\is_array($servers)) {
            return [];
        }

        $out = [];
        foreach ($servers as $server) {
            if (!\is_array($server)) {
                continue;
            }

            $url = $this->scalarOrNull($server, 'url');
            if ($url === null) {
                continue;
            }

            $out[] = new Server($url, $this->scalarOrNull($server, 'description'));
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $openapi
     *
     * @return list<Tag>
     */
    private function tags(array $openapi): array
    {
        $tags = $openapi['tags'] ?? [];
        if (!\is_array($tags)) {
            return [];
        }

        $out = [];
        foreach ($tags as $tag) {
            if (!\is_array($tag)) {
                continue;
            }

            $name = $this->scalarOrNull($tag, 'name');
            if ($name === null) {
                continue;
            }

            $out[] = new Tag($name, $this->scalarOrNull($tag, 'description'), $this->externalDocs($tag['externalDocs'] ?? null));
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $openapi
     *
     * @return array<string, SecurityScheme>
     */
    private function securitySchemes(array $openapi): array
    {
        $security = \is_array($openapi['security'] ?? null) ? $openapi['security'] : [];
        $schemes = \is_array($security['schemes'] ?? null) ? $security['schemes'] : [];

        $out = [];
        foreach ($schemes as $name => $scheme) {
            if (!\is_string($name) || $name === '' || !\is_array($scheme)) {
                continue;
            }

            $built = $this->securityScheme($scheme);
            if ($built !== null) {
                $out[$name] = $built;
            }
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $scheme
     */
    private function securityScheme(array $scheme): ?SecurityScheme
    {
        $type = \strtolower($this->scalarOrNull($scheme, 'type') ?? '');
        $description = $this->scalarOrNull($scheme, 'description');

        return match ($type) {
            'bearer' => SecurityScheme::bearer($this->scalarOrNull($scheme, 'bearerFormat'), $description),
            'http' => SecurityScheme::http($this->scalarOrNull($scheme, 'scheme') ?? 'bearer', $this->scalarOrNull($scheme, 'bearerFormat'), $description),
            'apikey' => SecurityScheme::apiKey(
                $this->scalarOrNull($scheme, 'apiKeyName') ?? 'Authorization',
                $this->scalarOrNull($scheme, 'in') ?? 'header',
                $description,
            ),
            'openidconnect' => $this->scalarOrNull($scheme, 'openIdConnectUrl') !== null
                ? SecurityScheme::openIdConnect((string) $this->scalarOrNull($scheme, 'openIdConnectUrl'), $description)
                : null,
            'oauth2' => $this->oauth2($scheme, $description),
            default => null,
        };
    }

    /**
     * Builds an `oauth2` scheme from its `flows` graph (§4.6, D8). A scheme with no usable
     * flow is dropped (returns null) rather than emitting an `oauth2` scheme with an empty
     * `flows` object, which would fail OAS 3.1 meta-validation.
     *
     * @param array<array-key, mixed> $scheme
     */
    private function oauth2(array $scheme, ?string $description): ?SecurityScheme
    {
        $flowsConfig = \is_array($scheme['flows'] ?? null) ? $scheme['flows'] : [];

        $implicit = $this->oauthFlow($flowsConfig, 'implicit');
        $password = $this->oauthFlow($flowsConfig, 'password');
        $clientCredentials = $this->oauthFlow($flowsConfig, 'clientCredentials');
        $authorizationCode = $this->oauthFlow($flowsConfig, 'authorizationCode');

        if ($implicit === null && $password === null && $clientCredentials === null && $authorizationCode === null) {
            return null;
        }

        return SecurityScheme::oauth2(
            new OAuthFlows($implicit, $password, $clientCredentials, $authorizationCode),
            $description,
        );
    }

    /**
     * One named flow off the `flows` graph, or null when the flow is absent or carries no
     * usable member (so an empty `{}` flow never makes it into the document).
     *
     * @param array<array-key, mixed> $flows
     */
    private function oauthFlow(array $flows, string $name): ?OAuthFlow
    {
        $flow = \is_array($flows[$name] ?? null) ? $flows[$name] : null;
        if ($flow === null) {
            return null;
        }

        $authorizationUrl = $this->scalarOrNull($flow, 'authorizationUrl');
        $tokenUrl = $this->scalarOrNull($flow, 'tokenUrl');
        $refreshUrl = $this->scalarOrNull($flow, 'refreshUrl');
        $scopes = $this->scopes($flow);

        if ($authorizationUrl === null && $tokenUrl === null && $refreshUrl === null && $scopes === []) {
            return null;
        }

        return new OAuthFlow($scopes, $authorizationUrl, $tokenUrl, $refreshUrl);
    }

    /**
     * The scope map of a flow (scope name => description), filtered to string=>string.
     *
     * @param array<array-key, mixed> $flow
     *
     * @return array<string, string>
     */
    private function scopes(array $flow): array
    {
        $scopes = \is_array($flow['scopes'] ?? null) ? $flow['scopes'] : [];

        $out = [];
        foreach ($scopes as $name => $description) {
            if (\is_string($name) && \is_string($description)) {
                $out[$name] = $description;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>           $openapi
     * @param array<string, SecurityScheme>  $schemes the resolved security schemes — a
     *                                                 default requirement naming a scheme
     *                                                 not present here is dropped (it would
     *                                                 otherwise be a dangling reference)
     *
     * @return list<SecurityRequirement>
     */
    private function defaultSecurity(array $openapi, array $schemes): array
    {
        $security = \is_array($openapi['security'] ?? null) ? $openapi['security'] : [];
        $requirements = \is_array($security['default_requirement'] ?? null) ? $security['default_requirement'] : [];

        $out = [];
        foreach ($requirements as $requirement) {
            if (!\is_array($requirement)) {
                continue;
            }

            $name = $this->scalarOrNull($requirement, 'name');
            if ($name === null) {
                continue;
            }

            // Drop a requirement that names a scheme which did not resolve into
            // components.securitySchemes — emitting it would produce a security reference
            // to an undefined scheme, which fails OAS 3.1 meta-validation.
            if (!isset($schemes[$name])) {
                continue;
            }

            $scopes = \is_array($requirement['scopes'] ?? null)
                ? \array_values(\array_filter($requirement['scopes'], '\is_string'))
                : [];

            $out[] = SecurityRequirement::scheme($name, $scopes);
        }

        return $out;
    }

    private function externalDocs(mixed $externalDocs): ?ExternalDocumentation
    {
        if (!\is_array($externalDocs)) {
            return null;
        }

        $url = $this->scalarOrNull($externalDocs, 'url');

        return $url === null ? null : new ExternalDocumentation($url, $this->scalarOrNull($externalDocs, 'description'));
    }

    /**
     * @param array<string, mixed> $openapi
     */
    private function jsonPath(array $openapi): string
    {
        $json = \is_array($openapi['json'] ?? null) ? $openapi['json'] : [];
        $path = $this->scalarOrNull($json, 'path') ?? '/docs.json';

        return '/' . \ltrim($path, '/');
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function scalarOrNull(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (\is_string($value)) {
            $value = \trim($value);

            return $value === '' ? null : $value;
        }

        return null;
    }
}
