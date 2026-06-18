<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Action;

/**
 * The URL scope a custom action hangs off (bundle ADR 0076, design §1).
 *
 * - {@see Resource}: `POST /{uriType}/{id}/-actions/{action}` — the `{id}` is
 *   resolved to an entity (via the type's `DataProvider`) before the handler runs;
 *   {@see ActionContext::entity()} returns it.
 * - {@see Collection}: `POST /{uriType}/-actions/{action}` — no id;
 *   {@see ActionContext::entity()} is `null`.
 */
enum ActionScope
{
    case Resource;
    case Collection;
}
