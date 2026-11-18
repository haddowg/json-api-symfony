<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedMeta;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

abstract class TmBoardResource extends AbstractResource
{
    public static string $type = 'tm-boards';

    public function getType(mixed $object): string
    {
        return $object instanceof TmBoard ? static::$type : '';
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('cards', 'tm-cards'),
            BelongsTo::make('pinned', 'tm-cards')->nullable(),
        ];
    }
}
