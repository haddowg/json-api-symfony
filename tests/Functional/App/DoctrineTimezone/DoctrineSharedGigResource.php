<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DoctrineTimezone;

use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseSharedGigResource;

#[AsJsonApiResource(entity: GigEntity::class, server: ['default', 'tokyo', 'raw'], readOnly: true)]
final class DoctrineSharedGigResource extends BaseSharedGigResource
{
    public function fields(): array
    {
        return [
            ...parent::fields(),
            BelongsToMany::make('performers', 'performers')
                ->through(GigPerformerEntity::class)
                ->fields(...self::performerPivotFields())
                ->extractUsing(static function (mixed $gig): array {
                    $performers = [];
                    foreach ($gig instanceof GigEntity ? $gig->gigPerformers : [] as $gigPerformer) {
                        if ($gigPerformer->performer !== null) {
                            $performers[] = $gigPerformer->performer;
                        }
                    }

                    return $performers;
                }),
        ];
    }
}
