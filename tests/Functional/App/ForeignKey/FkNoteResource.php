<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;

/**
 * `fk-notes`, which hide archived notes unless a request asks for them.
 */
abstract class FkNoteResource extends AbstractResource
{
    public static string $type = 'fk-notes';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('body'),
            Str::make('owner'),
            Boolean::make('archived'),
        ];
    }

    public function filters(): array
    {
        return [Where::make('archived')->asBoolean()->default(false)];
    }
}
