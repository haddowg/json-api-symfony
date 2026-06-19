<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Contact;
use haddowg\JsonApi\OpenApi\ExternalDocumentation;
use haddowg\JsonApi\OpenApi\License;
use haddowg\JsonApi\OpenApi\SecurityRequirement;
use haddowg\JsonApi\OpenApi\SecurityScheme;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\OpenApi\Tag;

/**
 * The config-shaped, OAS-shaped slice of a server's OpenAPI document — the data the
 * {@see MetadataSource} cannot derive from the live JSON:API registry because it has
 * **no JSON:API semantics**: the `info` block, the advertised servers, the tag
 * *definitions*, the security schemes + document-level default, and the external
 * docs (design §4.6/§4.7, D8/D15).
 *
 * It is a plain immutable carrier injected into the {@see MetadataSource} so Slice-4
 * stage B can build it from `json_api.openapi.*` config (and the per-server base URI)
 * without coupling stage A to the config tree. A `null`/empty member means the
 * document omits it; defaults (a fallback title/version, the per-server base URI as
 * the single advertised server) are the metadata source's job, applied when this
 * carrier supplies none.
 *
 * Tag definitions are config-**authoritative** but not exhaustive: the metadata
 * source unions them with name-only synthesized tags for any tag a type/action
 * *references* but config did not *define* (design §4.7) — so this carries only the
 * config-declared definitions, in config order.
 */
final readonly class ServerDocumentConfig
{
    /**
     * @param list<Server>                    $servers          the advertised OAS Server objects (empty = the source derives one from the per-server base URI)
     * @param list<Tag>                       $tagDefinitions   the config-declared tag definitions, in emit order (referenced-but-undefined tags are synthesized by the source)
     * @param array<string, SecurityScheme>  $securitySchemes  named security schemes for `components.securitySchemes`
     * @param list<SecurityRequirement>      $defaultSecurity  the document-level default security requirement (empty = none)
     */
    public function __construct(
        public ?string $title = null,
        public ?string $version = null,
        public ?string $description = null,
        public ?Contact $contact = null,
        public ?License $license = null,
        public array $servers = [],
        public array $tagDefinitions = [],
        public array $securitySchemes = [],
        public array $defaultSecurity = [],
        public ?ExternalDocumentation $externalDocs = null,
    ) {}
}
