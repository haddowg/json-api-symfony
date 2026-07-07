<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Attribute;

use haddowg\JsonApi\OpenApi\Metadata\ActionResponse;
use haddowg\JsonApi\OpenApi\Metadata\ActionResponses;
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
 * The request document is **decoupled from the mount type**: it defaults to it, but
 * `inputType` (Document mode only) may point at any other registered type — including
 * a standalone serializer/hydrator pair (a type with no endpoints of its own) — so an
 * action can accept a bespoke command document while staying valid JSON:API. A `null`
 * `inputType` resolves to `type`.
 *
 * `responds` declares the action's success response set for the generated OpenAPI
 * document (core's per-operation response declarations, ADR 0127) — the same atomic
 * response objects a resource declares, restricted to those valid on an action:
 *  - `new ActionResource('type')` → `200` with that type's document schema (a resource
 *    body; the handler returns a {@see \haddowg\JsonApi\Response\DataResponse} through
 *    that type's serializer — decoupled from the mount type, so it may name any
 *    registered type);
 *  - `new MetaResult()` → `200` with the shared meta-document schema (a handler
 *    returning `$context->meta([...])`);
 *  - `new NoContent()` → `204 No Content` (a handler returning `$context->noContent()`);
 *  - `new Accepted('job-type')` → `202 Accepted` for an asynchronous action (a job
 *    resource + `Content-Location` + `Retry-After`);
 *  - `new SeeOther()` → `303 See Other` (an async-completion redirect).
 *
 * A single response or a `list` of them is accepted; the set is validated at
 * declaration time via {@see ActionResponses::validate()}. When omitted, the action
 * defaults to a `200` resource document of its **mount type** (the resolved
 * `ActionResource(type)`). It affects only the generated document — the runtime
 * response is whatever the handler returns. The mount type's serializer renders a
 * {@see \haddowg\JsonApi\Response\DataResponse} unless an `ActionResource` names
 * another type.
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
     * The resolved success-response set for the generated OpenAPI document; `[]` when
     * omitted (the compiler pass then defaults it to a `200` resource document of the
     * mount type).
     *
     * @var list<ActionResponse>
     */
    public array $responds;

    /**
     * @param list<string>                       $methods  the author-declared HTTP method allow-list (default `['POST']`)
     * @param ActionResponse|list<ActionResponse>|null $responds the action's success response set (single or list); omitted = a `200` resource document of the mount type
     * @param list<string>                       $tags     the OpenAPI tag names this action is grouped under (empty = inherit the mount type's resource tags)
     * @param bool                               $asLink   expose the action as a security-aware `links` member on the mount type's resources (resource scope only)
     */
    public function __construct(
        public string $type,
        public string $path,
        public array $methods = ['POST'],
        public ActionScope $scope = ActionScope::Resource,
        public ActionInput $input = ActionInput::None,
        public ?string $inputType = null,
        ActionResponse|array|null $responds = null,
        public ?string $server = null,
        public ?string $security = null,
        public ?string $name = null,
        public array $tags = [],
        public bool $asLink = false,
    ) {
        $responds = $responds === null ? [] : (\is_array($responds) ? \array_values($responds) : [$responds]);
        if ($responds !== []) {
            ActionResponses::validate($responds);
        }
        $this->responds = $responds;

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
