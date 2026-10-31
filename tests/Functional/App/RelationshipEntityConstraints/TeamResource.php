<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationshipEntityConstraints;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;

/**
 * Declares no entity constraints, so emptying its companies is allowed.
 */
class TeamResource extends AbstractResource
{
    public static string $type = 'teams';

    public function fields(): array
    {
        return [
            Id::make(),
            BelongsToMany::make('companies', 'companies'),
        ];
    }
}
