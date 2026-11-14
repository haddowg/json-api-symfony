<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * A second type over {@see CogEntity}, registered before `cogs`, whose ids pass a
 * different encoder: an id filter on either type decodes with that type's own encoder.
 */
#[AsJsonApiResource(entity: CogEntity::class)]
final class OffsetCogResource extends AbstractResource
{
    public static string $type = 'offset-cogs';

    public function fields(): array
    {
        return [
            Id::make()->encodeUsing(new OffsetIdEncoder()),
            Str::make('name'),
        ];
    }

    public function filters(): array
    {
        return [
            WhereIdIn::make('id', 'id'),
            Where::make('idIs', 'id'),
        ];
    }
}
