<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotKeys;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: PkDefinitionEntity::class)]
final class PkClashDefinitionResource extends AbstractResource
{
    public static string $type = 'clash-definitions';

    public function fields(): array
    {
        return [
            Id::make()->uuid(),
            Str::make('name'),
            HasMany::make('pivot', 'clash-templates')
                ->extractUsing(static fn(mixed $definition): array => []),
        ];
    }
}
