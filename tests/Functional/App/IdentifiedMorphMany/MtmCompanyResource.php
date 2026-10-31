<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

abstract class MtmCompanyResource extends AbstractResource
{
    public function fields(): array
    {
        return [Id::make(), Str::make('name'), Str::make('code')];
    }
}
