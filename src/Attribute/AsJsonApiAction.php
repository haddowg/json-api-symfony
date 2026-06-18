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
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsJsonApiAction
{
    /**
     * @param list<string> $methods the author-declared HTTP method allow-list (default `['POST']`)
     */
    public function __construct(
        public string $type,
        public string $path,
        public array $methods = ['POST'],
        public ActionScope $scope = ActionScope::Resource,
        public ActionInput $input = ActionInput::None,
        public ?string $inputType = null,
        public ?string $outputType = null,
        public ?string $server = null,
        public ?string $security = null,
        public ?string $name = null,
    ) {}
}
