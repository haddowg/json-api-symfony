<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\Guard;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * A provider-backed user whose relations read through their foreign keys.
 */
#[AsJsonApiResource(readOnly: true)]
final class KeyedItemsUserResource extends AbstractResource
{
    public static string $type = 'fk-users';

    public function fields(): array
    {
        return [Id::make(), HasMany::make('items', 'fk-items', 'owner'), HasOne::make('profile', 'fk-profiles', 'owner')];
    }
}
