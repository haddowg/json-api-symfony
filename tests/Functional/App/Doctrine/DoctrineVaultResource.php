<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The `vaults` resource backing the constructor-less instantiation witness,
 * mapped to {@see VaultEntity} (whose constructor takes required arguments) via
 * `#[AsJsonApiResource(entity: …)]`. Minimal on purpose — an id and a plain
 * `secret` — so a create exercises the persister's {@see VaultEntity}
 * instantiation alone (ADR 0029).
 */
#[AsJsonApiResource(entity: VaultEntity::class)]
final class DoctrineVaultResource extends AbstractResource
{
    public static string $type = 'vaults';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('secret'),
        ];
    }
}
