<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Action;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\NoContentResponse;

/**
 * A custom, non-CRUD action (bundle ADR 0076, design §2). An author implements
 * this on a standalone handler class declared with
 * {@see \haddowg\JsonApiBundle\Attribute\AsJsonApiAction} — no `AbstractResource`
 * sugar — and is discovered by autoconfiguration exactly like a standalone
 * serializer/hydrator (ADR 0024).
 *
 * The handler receives the resolved {@see ActionContext} — the entity (resource
 * scope), the hydrated input (Document mode), the request, the query parameters,
 * the resolving server and the `outputType` serializer plus response conveniences
 * ({@see ActionContext::data()}/{@see ActionContext::meta()}/{@see ActionContext::noContent()})
 * — and returns a **core response value object** (no raw response): a
 * {@see DataResponse}/{@see MetaResponse} renders through the `outputType`
 * serializer via the existing view listener (so links, the JSON:API object and
 * error rendering are reused unchanged), a {@see NoContentResponse} yields a
 * bodyless `204`, an {@see ErrorResponse} renders a JSON:API error document.
 */
interface ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse|MetaResponse|NoContentResponse|ErrorResponse;
}
