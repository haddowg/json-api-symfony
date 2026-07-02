<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Attribute;

use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Action\ActionScope;

/**
 * Registers the annotated {@see \haddowg\JsonApiBundle\Action\ActionHandlerInterface}
 * as a **custom, non-CRUD action** hanging off a JSON:API `type` under the reserved
 * `-actions` segment (bundle ADR 0076). Discovery is by autoconfiguration, exactly
 * the standalone-serializer/hydrator pattern (ADR 0024) — there is no
 * `AbstractResource` sugar; an action is a standalone handler class.
 *
 * `type` is the **mount type**: the `{uriType}` URL segment the action hangs off,
 * and the default serializer (response) + hydrator (request document) the action
 * reuses. `path` is the single `{action}` URL segment (one action name).
 *
 * `scope` chooses the URL shape: {@see ActionScope::Resource} (default) mounts
 * `POST /{uriType}/{id}/-actions/{path}` and resolves the `{id}` to an entity
 * before the handler runs; {@see ActionScope::Collection} mounts
 * `POST /{uriType}/-actions/{path}` with no id.
 *
 * `methods` is the author-declared HTTP method allow-list (default `['POST']`; any
 * of GET/POST/PATCH/PUT/DELETE).
 *
 * `input` chooses the request-body contract (design §3): {@see ActionInput::None}
 * (default) reads no body; {@see ActionInput::Document} parses + validates +
 * hydrates a JSON:API document into an `inputType` instance; {@see ActionInput::Raw}
 * relaxes request content-type negotiation for a non-JSON:API upload.
 *
 * The request and response documents are **decoupled from the mount type**: both
 * default to it, but `inputType` (Document mode only) and `outputType` may point at
 * any other registered type — including a standalone serializer/hydrator pair (a
 * Phase-4 type with no endpoints of its own) — so an action can accept a bespoke
 * command document and/or return a bespoke response document while staying valid
 * JSON:API. A `null` `inputType`/`outputType` resolves to `type`.
 *
 * `returns204` declares the action returns **no response body** (a `204 No Content`,
 * e.g. a handler returning `$context->noContent()`): it suppresses the `outputType`
 * default so the generated OpenAPI document advertises a `204` response instead of a
 * `200` document body (design §4.5). It is mutually exclusive with an explicit
 * `outputType` (a `204` action describes no body). It affects only the generated
 * document — the runtime response is whatever the handler returns.
 *
 * `outputMeta` declares the action returns a **meta-only document** — a JSON:API
 * document whose primary content is its top-level `meta`, with no `data` (a handler
 * returning `$context->meta([...])`): the generated document advertises a `200` with
 * the shared meta-document schema instead of a resource-document body (core ADR
 * 0102). Like `returns204` it suppresses the `outputType` default and is mutually
 * exclusive with an explicit `outputType` (a meta document carries no resource) and
 * with `returns204` (an action answers one way). It affects only the generated
 * document — the runtime response is whatever the handler returns.
 *
 * `server` names the server this action is exposed on (a single server name, or
 * `null` for the implicit `default` server).
 *
 * `security` is an optional Symfony Security
 * {@see https://symfony.com/doc/current/security/expressions.html ExpressionLanguage}
 * string, evaluated against the resolved entity (resource scope) / `null`
 * (collection scope) at the per-action `BeforeActionEvent` gate, denying with a
 * `403` on a false result (design §6). It rides on the action, not the type.
 *
 * `name` is an optional route-name override.
 *
 * `tags` declares the **OpenAPI tag names** this action's operation is grouped under
 * in the generated OpenAPI document (design §4.7, D15). An empty array means the
 * default: inherit the resource tag(s) of the action's mount `type`, so actions
 * group with their resource. Tags carry no JSON:API meaning.
 *
 * `asLink` exposes the action as a `links` member on every rendered resource of its
 * mount `type` — a host-owned, router-generated link the resource's own
 * serializer knows nothing about, merged out-of-band through core's
 * {@see \haddowg\JsonApi\Serializer\ResourceLinkContributorInterface} seam (the
 * link is keyed by the action's `path`). It is **security-aware**: when the action
 * declares a `security` expression, the link renders only when the requester would
 * pass that same gate (the same {@see \Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface}
 * evaluation the per-action `BeforeActionEvent` gate uses), so a client never sees a
 * link to an action it cannot invoke. It is **resource-scope only** — a
 * {@see ActionScope::Collection} action has no resource to hang a link on, so
 * `asLink: true` with a collection scope is a build-time error.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsJsonApiAction
{
    /**
     * @param list<string> $methods    the author-declared HTTP method allow-list (default `['POST']`)
     * @param bool         $returns204 the action returns no response body (the document advertises `204` instead of a `200` body); mutually exclusive with `outputType` and `outputMeta`
     * @param bool         $outputMeta the action returns a meta-only document (the document advertises a `200` meta document instead of a resource body); mutually exclusive with `outputType` and `returns204`
     * @param list<string> $tags       the OpenAPI tag names this action is grouped under (empty = inherit the mount type's resource tags)
     * @param bool         $asLink     expose the action as a security-aware `links` member on the mount type's resources (resource scope only)
     */
    public function __construct(
        public string $type,
        public string $path,
        public array $methods = ['POST'],
        public ActionScope $scope = ActionScope::Resource,
        public ActionInput $input = ActionInput::None,
        public ?string $inputType = null,
        public ?string $outputType = null,
        public bool $returns204 = false,
        public bool $outputMeta = false,
        public ?string $server = null,
        public ?string $security = null,
        public ?string $name = null,
        public array $tags = [],
        public bool $asLink = false,
    ) {
        // An action answers exactly one way. A `204` and a meta-only document are both
        // body-shape declarations that suppress the `outputType` default, so declaring
        // both — or either alongside an explicit `outputType` — is contradictory.
        if ($returns204 && $outputMeta) {
            throw new \LogicException(\sprintf(
                'The JSON:API action "%s" on type "%s" declares both returns204 and outputMeta; an action answers '
                . 'exactly one way, so they are mutually exclusive.',
                $path,
                $type,
            ));
        }

        if ($outputMeta && $outputType !== null) {
            throw new \LogicException(\sprintf(
                'The JSON:API action "%s" on type "%s" declares both outputMeta and an outputType; a meta-only '
                . 'document carries no resource, so they are mutually exclusive.',
                $path,
                $type,
            ));
        }

        // A collection-scope action has no resource to hang a link on, so exposing
        // it as a resource link is incoherent — reject it at declaration time.
        if ($asLink && $scope === ActionScope::Collection) {
            throw new \LogicException(\sprintf(
                'The JSON:API action "%s" on type "%s" declares asLink with a Collection scope; a resource link '
                . 'requires a resource to hang off, so asLink is supported only for a Resource-scope action.',
                $path,
                $type,
            ));
        }
    }
}
