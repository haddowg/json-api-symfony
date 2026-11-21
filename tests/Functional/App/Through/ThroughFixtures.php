<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Through;

use haddowg\JsonApiBundle\Tests\Functional\App\Through\Doctrine\ThrAdvertiserEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Through\Doctrine\ThrCampaignEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Through\Doctrine\ThrLeadEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Through\Doctrine\ThrTemplateEntity;

/**
 * The lead graph both kernels serve, the Doctrine one after persisting it.
 *
 * Advertisers 4 Acme, 7 Globex and 9 Shadow, which the advertisers' scope hides.
 * Campaigns, keyed by UUID:
 *
 *  - spring: Acme, budget 500, starts 2026-03-02
 *  - summer: Globex, budget 150, starts 2026-06-15
 *  - autumn: Shadow, budget 300, starts 2026-09-01, archived 2026-10-01
 *  - winter: no advertiser, budget 50, starts 2026-12-01
 *
 * Templates 1 Welcome, 2 Reminder and 3 Survey, served as `tpl-<id>`. Leads:
 *
 *  - 1 Ada, spring, templates 1 and 2
 *  - 2 Grace, summer, template 2
 *  - 3 Hedy, autumn, no templates
 *  - 4 Joan, spring, template 3
 *  - 5 Katherine, no campaign, template 1
 *  - 6 Lin, winter, no templates
 */
final class ThroughFixtures
{
    public const string SPRING = '7a1c0d52-0000-4000-8000-000000000001';

    public const string SUMMER = '7a1c0d52-0000-4000-8000-000000000002';

    public const string AUTUMN = '7a1c0d52-0000-4000-8000-000000000003';

    public const string WINTER = '7a1c0d52-0000-4000-8000-000000000004';

    /**
     * @return array{advertisers: list<ThrAdvertiserEntity>, templates: list<ThrTemplateEntity>, campaigns: list<ThrCampaignEntity>, leads: list<ThrLeadEntity>}
     */
    public static function graph(): array
    {
        $advertisers = [4 => new ThrAdvertiserEntity(4, 'Acme'), 7 => new ThrAdvertiserEntity(7, 'Globex'), 9 => new ThrAdvertiserEntity(9, 'Shadow', true)];
        $templates = [1 => new ThrTemplateEntity(1, 'Welcome'), 2 => new ThrTemplateEntity(2, 'Reminder'), 3 => new ThrTemplateEntity(3, 'Survey')];
        $campaigns = [
            self::SPRING => new ThrCampaignEntity(self::SPRING, 'Spring sale', 500, new \DateTimeImmutable('2026-03-02'), null, $advertisers[4]),
            self::SUMMER => new ThrCampaignEntity(self::SUMMER, 'Summer launch', 150, new \DateTimeImmutable('2026-06-15'), null, $advertisers[7]),
            self::AUTUMN => new ThrCampaignEntity(self::AUTUMN, 'Autumn', 300, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-10-01T00:00:00Z'), $advertisers[9]),
            self::WINTER => new ThrCampaignEntity(self::WINTER, 'Winter', 50, new \DateTimeImmutable('2026-12-01'), null, null),
        ];
        foreach ($campaigns as $campaign) {
            $campaign->advertiser?->campaigns->add($campaign);
        }

        $leads = [];
        foreach ([
            [1, 'Ada', self::SPRING, [1, 2]],
            [2, 'Grace', self::SUMMER, [2]],
            [3, 'Hedy', self::AUTUMN, []],
            [4, 'Joan', self::SPRING, [3]],
            [5, 'Katherine', null, [1]],
            [6, 'Lin', self::WINTER, []],
        ] as [$id, $name, $campaign, $templateIds]) {
            $lead = new ThrLeadEntity($id, $name, $campaign !== null ? $campaigns[$campaign] : null);
            foreach ($templateIds as $templateId) {
                $lead->templates->add($templates[$templateId]);
            }
            $lead->campaign?->leads->add($lead);
            $leads[] = $lead;
        }

        return [
            'advertisers' => \array_values($advertisers),
            'templates' => \array_values($templates),
            'campaigns' => \array_values($campaigns),
            'leads' => $leads,
        ];
    }
}
