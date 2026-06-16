<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The Doctrine `hookOwners` resource: registering it makes the type known so a
 * widget's `owner` relationship can emit linkage and the persister can resolve a
 * linkage id to a managed reference.
 */
#[AsJsonApiResource(entity: HookOwnerEntity::class)]
final class DoctrineHookOwnerResource extends AbstractResource
{
    public static string $type = 'hookOwners';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
