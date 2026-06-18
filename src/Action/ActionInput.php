<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Action;

/**
 * How a custom action's request body is handled (bundle ADR 0076, design §3).
 *
 * - {@see None} (default): no body is read; the request `Content-Type` is not
 *   required; {@see ActionContext::input()} is `null`.
 * - {@see Document}: the body is parsed + structurally validated as JSON:API and
 *   semantically validated through the Validator bridge against the action's
 *   `inputType`, then hydrated into a fresh instance; {@see ActionContext::input()}
 *   is that hydrated object.
 * - {@see Raw} (escape hatch): the request `Content-Type` negotiation is relaxed
 *   (a `multipart/form-data` upload is not `application/vnd.api+json`); no JSON:API
 *   body parsing/validation runs; the handler reads the raw body and uploaded files
 *   off {@see ActionContext::request()}; {@see ActionContext::input()} is `null`.
 */
enum ActionInput
{
    case None;
    case Document;
    case Raw;
}
