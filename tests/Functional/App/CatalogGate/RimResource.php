<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\CatalogGate;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true)]
final class RimResource extends AbstractResource
{
    public static string $type = 'rims';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof Spoke ? 'spokes' : static::$type;
    }
}
