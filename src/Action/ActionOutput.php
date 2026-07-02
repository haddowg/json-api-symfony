<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Action;

/**
 * How a custom action answers on success — the declared shape of its response, used
 * only to project the action's success response in the generated OpenAPI document
 * (the runtime response is whatever the handler returns). Mirrors core's
 * {@see \haddowg\JsonApi\OpenApi\Metadata\ActionOutputMode}.
 *
 * - {@see Document} (default): a JSON:API document whose `data` is the action's
 *   `outputType` resource (a `200` with that type's document schema; the handler
 *   returns a {@see \haddowg\JsonApi\Response\DataResponse}).
 * - {@see Meta}: a JSON:API document whose primary content is its top-level `meta`,
 *   no `data` (a `200` with the shared meta-document schema; the handler returns a
 *   {@see \haddowg\JsonApi\Response\MetaResponse} via {@see ActionContext::meta()}).
 *   Declared with `#[AsJsonApiAction(outputMeta: true)]`.
 * - {@see None}: no response body (a `204 No Content`; the handler returns a
 *   {@see \haddowg\JsonApi\Response\NoContentResponse} via {@see ActionContext::noContent()}).
 *   Declared with `#[AsJsonApiAction(returns204: true)]`.
 */
enum ActionOutput
{
    case Document;
    case Meta;
    case None;
}
