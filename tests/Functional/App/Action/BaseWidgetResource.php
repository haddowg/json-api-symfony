<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The shared mount-type declaration both custom-action kernels serve: an
 * `actionWidgets` type (a distinct name from the existing `widgets` fixture so the
 * two never collide). Its fields are deliberately minimal — the suite hangs custom
 * actions off it, it is not exercising the CRUD field DSL.
 *
 * `published` and `uploadedArtwork` are the columns the resource-scope Document
 * action and the Raw upload action mutate, so a follow-up `GET /actionWidgets/{id}`
 * witnesses the side-effect on either provider through identical assertions.
 */
abstract class BaseWidgetResource extends AbstractResource
{
    public static string $type = 'actionWidgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            Boolean::make('published'),
            Str::make('uploadedArtwork')->nullable(),
        ];
    }
}
