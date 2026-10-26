<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DoctrineTimezone;

use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Link\DeclaredLink;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseGigResource;

/**
 * The Doctrine `gigs`: the shared fields plus a pivot-backed `performers` relation
 * (pivot meta is Doctrine-only). `bookedAt` takes the server zone, `confirmedAt` keeps
 * its own, `contractOn` is a `Date` the server zone leaves alone. `bookedPerformers`
 * is the same association with `withData()`, so its linkage and pivot meta render on
 * the primary document, which puts the bundle's pivot parent serializer in front of
 * this resource. Its declared `poster` link has to get through that serializer.
 */
#[AsJsonApiResource(entity: GigEntity::class, readOnly: true)]
final class DoctrineGigResource extends BaseGigResource
{
    public function fields(): array
    {
        return [
            ...parent::fields(),
            BelongsToMany::make('performers', 'performers')
                ->through(GigPerformerEntity::class)
                ->fields(...self::pivotFields())
                ->extractUsing(self::extractPerformers()),
            BelongsToMany::make('bookedPerformers', 'performers')
                ->through(GigPerformerEntity::class)
                ->fields(...self::pivotFields())
                ->extractUsing(self::extractPerformers())
                ->withData(),
        ];
    }

    public function links(): array
    {
        return [
            DeclaredLink::make('poster')
                ->hrefUsing(static fn(mixed $gig): ?string => $gig instanceof GigEntity
                    ? '/gigs/' . $gig->id . '/poster'
                    : null),
        ];
    }

    /**
     * @return list<\haddowg\JsonApi\Resource\Field\FieldInterface>
     */
    private static function pivotFields(): array
    {
        return [
            DateTime::make('bookedAt')->readOnly()->build(),
            DateTime::make('confirmedAt')->outputTimezone('America/New_York')->readOnly()->build(),
            Date::make('contractOn')->readOnly()->build(),
        ];
    }

    private static function extractPerformers(): \Closure
    {
        return static function (mixed $gig): array {
            if (!$gig instanceof GigEntity) {
                return [];
            }

            $performers = [];
            foreach ($gig->gigPerformers as $gigPerformer) {
                if ($gigPerformer->performer !== null) {
                    $performers[] = $gigPerformer->performer;
                }
            }

            return $performers;
        };
    }
}
