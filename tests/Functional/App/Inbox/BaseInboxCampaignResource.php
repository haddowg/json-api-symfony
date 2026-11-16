<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Inbox;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Tests\Functional\App\Inbox\Doctrine\InboxCampaignEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\PrefixIdEncoder;

/**
 * Campaigns whose integer key is served as `cmp-<id>`.
 */
abstract class BaseInboxCampaignResource extends AbstractResource
{
    public static string $type = 'inbCampaigns';

    public function fields(): array
    {
        return [
            Id::make()->encodeUsing(new PrefixIdEncoder('cmp-')),
            Str::make('name'),
        ];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof InboxCampaignEntity ? static::$type : '';
    }
}
