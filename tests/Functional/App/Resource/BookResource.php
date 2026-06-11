<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The uriType witness: a resource whose JSON:API type (`book`) differs from its
 * URL path segment (`books`). The route loader emits the routes at `/books`, the
 * Location header and the relationship convention links use `/books`, but the
 * rendered document `type` member stays `book` (ADR 0022 / core ADR 0031). The
 * self-referential `related` relationship exists only to render convention links
 * whose segment can be asserted.
 */
final class BookResource extends AbstractResource
{
    public static string $type = 'book';

    public static string $uriType = 'books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required(),
            BelongsTo::make('related')->type('book'),
        ];
    }
}
