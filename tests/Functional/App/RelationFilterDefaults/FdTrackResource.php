<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationFilterDefaults;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;

/**
 * Tracks hide explicit and archived rows unless a request asks for them.
 */
final class FdTrackResource extends AbstractResource
{
    public static string $type = 'tracks';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->sortable(),
            Boolean::make('explicit'),
            Boolean::make('archived'),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('explicit')->asBoolean()->default(false),
            Where::make('archived')->asBoolean()->default(false),
        ];
    }
}
