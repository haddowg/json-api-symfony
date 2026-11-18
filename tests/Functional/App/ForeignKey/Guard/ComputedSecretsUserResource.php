<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\Guard;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * A provider-backed user whose secrets, a scoped type, its own closure computes.
 */
#[AsJsonApiResource(readOnly: true)]
final class ComputedSecretsUserResource extends AbstractResource
{
    public static string $type = 'fk-users';

    public function fields(): array
    {
        return [Id::make(), HasMany::make('secrets', 'fk-secrets')->extractUsing(static fn(): array => [])];
    }
}
