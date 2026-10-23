<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true)]
final class OpaqueAgencyResource extends AbstractResource
{
    public static string $type = 'opaque-agencies';

    public function getType(mixed $object): string
    {
        return $object instanceof ScopingMember ? 'opaque-members' : 'opaque-agencies';
    }

    public function fields(): array
    {
        return [Id::make(), Str::make('name')];
    }
}
