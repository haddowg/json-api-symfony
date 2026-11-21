<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotNumbers;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A board's `links` are declared link rows carrying an integer `count` and a number
 * `weight`.
 */
class PnBoardResource extends AbstractResource
{
    public static string $type = 'pn-boards';

    public function fields(): array
    {
        return [
            Id::make()->allowClientId(),
            Str::make('name'),
            MorphToMany::make('links', ['pn-cards'])
                ->through(PnLink::class)
                ->identifiedByRows(owner: 'board', type: 'itemType', id: 'itemId')
                ->fields(Integer::make('count'), Decimal::make('weight'))
                ->withData(),
        ];
    }
}
