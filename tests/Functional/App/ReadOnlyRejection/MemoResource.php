<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ReadOnlyRejection;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * `author` is never writable, `slug` only on create, and `body` must be at least three
 * characters, so one body can break both the read-only rule and a constraint.
 */
abstract class MemoResource extends AbstractResource
{
    public static string $type = 'memos';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('body')->minLength(3),
            Str::make('author')->readOnly(),
            Str::make('slug')->nullable()->readOnlyOnUpdate(),
        ];
    }
}
