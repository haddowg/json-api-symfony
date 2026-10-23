<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A required field of each wire type, shared by both providers: required means
 * non-empty for a string, list or map and only non-null for a boolean or integer,
 * so `false` and `0` are valid values. `note` is required but nullable, and the
 * `options` object repeats the boolean and integer rule one level down.
 */
abstract class BaseChecklistResource extends AbstractResource
{
    public static string $type = 'checklists';

    public function fields(): array
    {
        return [
            Id::make(),
            Boolean::make('done')->required(),
            Integer::make('count')->required(),
            Str::make('title')->required(),
            ArrayList::make('tags')->required(),
            ArrayHash::make('settings')->required(),
            Str::make('note')->required()->nullable(),
            Obj::make('options')->nullable()->fields(
                Boolean::make('enabled')->required(),
                Integer::make('retries')->required(),
            ),
        ];
    }
}
