<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Inbox;

use haddowg\JsonApiBundle\Tests\Functional\App\Inbox\Doctrine\InboxCampaignEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Inbox\Doctrine\InboxFolderEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Inbox\Doctrine\InboxItemEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Inbox\Doctrine\InboxLinkEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Inbox\Doctrine\RfpEntity;

/**
 * The inbox both providers serve.
 *
 * | item | folder | subject           | read | archived | links                     |
 * |------|--------|-------------------|------|----------|---------------------------|
 * | 1    | 1      | inbRfps 812       |      |          | inbRfps 812               |
 * | 2    | 1      | inbRfps 813       | yes  |          | inbCampaigns 1            |
 * | 3    | 1      | inbCampaigns 1    |      | yes      | inbRfps 813, inbCampaigns 2 |
 * | 4    | 2      | inbCampaigns 2    | yes  | yes      |                           |
 * | 5    | 2      |                   |      |          |                           |
 */
final class InboxFixtures
{
    /**
     * @return array{folders: list<InboxFolderEntity>, items: list<InboxItemEntity>, links: list<InboxLinkEntity>, rfps: list<RfpEntity>, campaigns: list<InboxCampaignEntity>}
     */
    public static function graph(): array
    {
        $main = new InboxFolderEntity(1, 'Main');
        $other = new InboxFolderEntity(2, 'Other');
        $at = new \DateTimeImmutable('2026-11-01T09:00:00+00:00');

        $items = [
            new InboxItemEntity(1, 'New RFP', $main, 'inbRfps', '812'),
            new InboxItemEntity(2, 'RFP answered', $main, 'inbRfps', '813', readTs: $at),
            new InboxItemEntity(3, 'Campaign live', $main, 'inbCampaigns', '1', archivedTs: $at),
            new InboxItemEntity(4, 'Campaign closed', $other, 'inbCampaigns', '2', readTs: $at, archivedTs: $at),
            new InboxItemEntity(5, 'Welcome', $other),
        ];
        foreach ($items as $item) {
            $item->folder?->items->add($item);
        }

        $links = [
            new InboxLinkEntity(1, $items[0], 'inbRfps', '812'),
            new InboxLinkEntity(2, $items[1], 'inbCampaigns', '1'),
            new InboxLinkEntity(3, $items[2], 'inbRfps', '813'),
            new InboxLinkEntity(4, $items[2], 'inbCampaigns', '2'),
        ];
        foreach ($links as $link) {
            $link->item->links->add($link);
        }

        return [
            'folders' => [$main, $other],
            'items' => $items,
            'links' => $links,
            'rfps' => [new RfpEntity(812, 'Office move'), new RfpEntity(813, 'Fleet'), new RfpEntity(814, 'Catering')],
            'campaigns' => [new InboxCampaignEntity(1, 'Spring'), new InboxCampaignEntity(2, 'Summer')],
        ];
    }
}
