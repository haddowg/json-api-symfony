<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Include\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The Capability C witness root: a `roots` resource whose to-one `node` reaches the
 * `nodes` chain, with an allowed-include-paths whitelist of exactly `['node']`. So
 * `GET /roots/{id}?include=node` succeeds, but `?include=node.next` and
 * `?include=node.tag` are a `400` — even though `next` and `tag` are themselves
 * includable from `nodes`' own root. This is the headline a per-relation
 * `cannotBeIncluded()` (Capability A) cannot express: a path forbidden ONLY when
 * reached as a nested path from this parent (bundle ADR 0037).
 *
 * The whitelist is evaluated once against this primary/root resource and governs
 * the whole nested tree, so it does not need to enumerate `node.*` — listing only
 * the permitted full paths forbids every other.
 */
abstract class BaseRootResource extends AbstractResource
{
    public static string $type = 'roots';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
            BelongsTo::make('node', 'nodes'),
        ];
    }

    public function getAllowedIncludePaths(): ?array
    {
        return ['node'];
    }
}
