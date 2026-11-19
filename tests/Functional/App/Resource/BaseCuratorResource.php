<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The related type of the field-selection witness, whose `stats` keys a predicate makes
 * selectable, so a selection reaches `included` and the related endpoint.
 */
abstract class BaseCuratorResource extends AbstractResource
{
    public static string $type = 'curators';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            Obj::make('stats')->nullable()
                ->additionalProperties(Integer::make('*'))
                ->selectableKeys(static fn(string $key, JsonApiRequestInterface $request): bool => $key !== 'secret'),
        ];
    }
}
