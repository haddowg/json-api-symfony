<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Inbox;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\WhereMorph;
use haddowg\JsonApi\Resource\Filter\WhereSet;
use haddowg\JsonApiBundle\Tests\Functional\App\Inbox\Doctrine\InboxItemEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Inbox\Doctrine\InboxLinkEntity;

/**
 * Inbox items about an rfp or a campaign. The subject is linkage only, with no related
 * endpoint and no include, and archived items are left out unless a client asks.
 */
abstract class BaseInboxItemResource extends AbstractResource
{
    public static string $type = 'inbItems';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('title'),
            DateTime::make('readTs')->nullable(),
            DateTime::make('archivedTs')->nullable(),
            MorphTo::make('subject', ['inbRfps', 'inbCampaigns'])
                ->identifiedByColumns(type: 'subjectType', id: 'subjectId')
                ->cannotBeIncluded()
                ->withoutRelatedEndpoint(),
            MorphToMany::make('links', ['inbRfps', 'inbCampaigns'])
                ->through(InboxLinkEntity::class)
                ->identifiedByRows(owner: 'item', type: 'linkedType', id: 'linkedId')
                ->cannotBeIncluded()
                ->withoutRelatedEndpoint(),
        ];
    }

    public function filters(): array
    {
        return [
            WhereSet::make('read', 'readTs'),
            WhereSet::make('archived', 'archivedTs')->default(false)->describedAs('Whether the item is archived. Archived items are left out unless asked for.'),
            WhereMorph::make('subject')->describedAs('Items about this resource.')->example(['type' => 'inbRfps', 'id' => ['812']]),
            WhereMorph::make('linked', 'links'),
        ];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof InboxItemEntity ? static::$type : '';
    }
}
