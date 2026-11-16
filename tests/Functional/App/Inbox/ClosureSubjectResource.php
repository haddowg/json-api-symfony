<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Inbox;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Filter\WhereMorph;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Inbox\Doctrine\InboxItemEntity;

/**
 * A WhereMorph over a MorphTo identified only by a closure, which the Doctrine provider
 * cannot query.
 */
#[AsJsonApiResource(entity: InboxItemEntity::class, readOnly: true)]
final class ClosureSubjectResource extends AbstractResource
{
    public static string $type = 'inbClosureItems';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            MorphTo::make('subject', ['inbRfps', 'inbCampaigns'])
                ->identifiedBy(static fn(mixed $item): ?array => $item instanceof InboxItemEntity && $item->subjectType !== null && $item->subjectId !== null ? [$item->subjectType, $item->subjectId] : null)
                ->cannotBeIncluded()
                ->withoutRelatedEndpoint(),
        ];
    }

    public function filters(): array
    {
        return [WhereMorph::make('subject')];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof InboxItemEntity ? static::$type : '';
    }
}
