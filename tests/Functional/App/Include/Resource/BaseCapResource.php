<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Include\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The Capability B per-resource override witness: a `caps` resource whose to-one
 * `node` reaches the `nodes` chain, with a `maxIncludeDepth()` override of `1`. The
 * server default is `3` (the bundle's
 * {@see \haddowg\JsonApiBundle\JsonApiBundle::DEFAULT_MAX_INCLUDE_DEPTH}), but this
 * resource's own override wins when `caps` is the primary/root type — so
 * `?include=node` (depth 1) succeeds while `?include=node.next` (depth 2) is a `400`,
 * proving the per-resource override beats the server default (bundle ADR 0037).
 */
abstract class BaseCapResource extends AbstractResource
{
    public static string $type = 'caps';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
            BelongsTo::make('node')->type('nodes'),
        ];
    }

    public function maxIncludeDepth(): ?int
    {
        return 1;
    }
}
