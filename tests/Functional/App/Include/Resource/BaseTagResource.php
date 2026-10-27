<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Include\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The shared `tags` declaration for the include-safeguard kernels. Its to-one
 * `node` relation is includable from the `tags` root — `GET /tags/{id}?include=node`
 * succeeds — which is exactly the contrast the Capability C allow-list headline
 * needs: the SAME relation a parent's whitelist forbids as a nested path is freely
 * includable when `tags` is the request's own root.
 *
 * `anyNode` is a `MorphTo` over `nodes` alone reading the same column as `node`. At
 * runtime it takes the same nested includes, but the OpenAPI document treats it as
 * polymorphic and lists no path through it.
 */
abstract class BaseTagResource extends AbstractResource
{
    public static string $type = 'tags';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('node', 'nodes'),
            MorphTo::make('anyNode', ['nodes'])->storedAs('node'),
        ];
    }
}
