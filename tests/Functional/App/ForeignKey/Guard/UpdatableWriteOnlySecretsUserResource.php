<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\Guard;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Operation\Operation;

/**
 * A provider-backed user that takes an update, whose write-only secrets, a scoped type,
 * name no foreign key, so replacing them reads the members a client can't see.
 */
#[AsJsonApiResource(operations: [Operation::FetchOne, Operation::Update])]
final class UpdatableWriteOnlySecretsUserResource extends AbstractResource
{
    public static string $type = 'fk-users';

    public function fields(): array
    {
        return [Id::make(), HasMany::make('secrets', 'fk-secrets')->writeOnly()];
    }
}
