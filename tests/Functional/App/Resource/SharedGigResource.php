<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Gig;

#[AsJsonApiResource(server: ['default', 'tokyo', 'raw'], readOnly: true)]
final class SharedGigResource extends BaseSharedGigResource
{
    public function fields(): array
    {
        return [
            ...parent::fields(),
            BelongsToMany::make('performers', 'performers')
                ->fields(...self::performerPivotFields())
                ->extractUsing(static fn(mixed $gig): array => $gig instanceof Gig ? $gig->performers : []),
        ];
    }
}
