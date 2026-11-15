<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FillUsing;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: FillShelf::class)]
final class DoctrineFillShelfResource extends AbstractResource
{
    public static string $type = 'fill-shelves';

    public function fields(): array
    {
        return [Id::make(), HasMany::make('slots', 'fill-slots')];
    }
}
