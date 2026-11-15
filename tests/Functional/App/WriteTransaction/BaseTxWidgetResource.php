<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

abstract class BaseTxWidgetResource extends AbstractResource
{
    public static string $type = 'txWidgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('notes', 'txNotes'),
        ];
    }
}
