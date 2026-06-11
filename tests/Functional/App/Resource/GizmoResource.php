<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The endpoint-exposure witness resource (ADR 0027): one `gizmos` type whose
 * relations exercise the per-relation endpoint-exposure flags the handler
 * enforces. Each suppressed/locked variant reuses an underlying property
 * (`storedAs()`) so they read identically to the plain `author`/`comments`
 * controls and only the exposure flags differ:
 *  - `secretAuthor` suppresses its *related* endpoint (`withoutRelatedEndpoint()`)
 *    — `GET /gizmos/{id}/secretAuthor` is a `404`, its relationship endpoint stays;
 *  - `hiddenAuthor` suppresses its *relationship* endpoint
 *    (`withoutRelationshipEndpoint()`) — `GET /gizmos/{id}/relationships/hiddenAuthor`
 *    is a `404`, its related endpoint stays;
 *  - `lockedComments` forbids additions (`cannotAdd()`) — a `POST` add is a `403`.
 *
 * Storage-orthogonal (every assertion fires before any write), so witnessed on the
 * in-memory kernel only.
 */
final class GizmoResource extends AbstractResource
{
    public static string $type = 'gizmos';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('author')->type('authors'),
            BelongsTo::make('secretAuthor')->type('authors')->storedAs('author')->withoutRelatedEndpoint(),
            BelongsTo::make('hiddenAuthor')->type('authors')->storedAs('author')->withoutRelationshipEndpoint(),
            HasMany::make('comments')->type('comments'),
            HasMany::make('lockedComments')->type('comments')->storedAs('comments')->cannotAdd(),
        ];
    }
}
