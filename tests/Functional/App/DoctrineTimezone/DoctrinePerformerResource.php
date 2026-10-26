<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DoctrineTimezone;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Link\DeclaredLink;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: PerformerEntity::class, readOnly: true)]
final class DoctrinePerformerResource extends AbstractResource
{
    public static string $type = 'performers';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }

    public function links(): array
    {
        return [
            DeclaredLink::make('profile')
                ->hrefUsing(static fn(mixed $performer): ?string => $performer instanceof PerformerEntity
                    ? '/performers/' . $performer->id . '/profile'
                    : null),
        ];
    }
}
