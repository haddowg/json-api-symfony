<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdSource\Doctrine;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The require-client-id witness (bundle ADR 0039): `requireClientId()` mandates a
 * client-supplied `data.id` on create — a create that omits it 403s
 * `ClientGeneratedIdRequired`, a create that carries it uses it as the natural key.
 */
#[AsJsonApiResource(entity: TokenEntity::class)]
final class TokenResource extends AbstractResource
{
    public static string $type = 'tokens';

    public function fields(): array
    {
        return [
            Id::make()->requireClientId(),
            Str::make('value')->required(),
        ];
    }
}
