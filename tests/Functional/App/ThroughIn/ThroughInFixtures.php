<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn;

use haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\Doctrine\AdvertiserEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\Doctrine\CampaignEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\Doctrine\LeadEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\Doctrine\TemplateEntity;

/**
 * The lead graph both kernels serve, the Doctrine one after persisting it.
 *
 * Advertisers 4 Acme, 7 Globex and 9 Initech. Campaigns spring (Acme), summer
 * (Globex) and autumn (Initech), keyed by UUID. Templates 1 Welcome, 2 Reminder and
 * 3 Survey, served as `tpl-<id>`. Leads:
 *
 *  - 1 open, spring, templates 1 and 2
 *  - 2 open, summer, template 2
 *  - 3 open, autumn, no templates
 *  - 4 closed, spring, template 3
 *  - 5 open, no campaign, template 1
 */
final class ThroughInFixtures
{
    public const string SPRING = '0b5f4c62-2f1e-4b9a-9a57-1f0c3c4d5e01';

    public const string SUMMER = '0b5f4c62-2f1e-4b9a-9a57-1f0c3c4d5e02';

    public const string AUTUMN = '0b5f4c62-2f1e-4b9a-9a57-1f0c3c4d5e03';

    /**
     * @return array{advertisers: list<AdvertiserEntity>, templates: list<TemplateEntity>, campaigns: list<CampaignEntity>, leads: list<LeadEntity>}
     */
    public static function graph(): array
    {
        $advertisers = [4 => new AdvertiserEntity(4, 'Acme'), 7 => new AdvertiserEntity(7, 'Globex'), 9 => new AdvertiserEntity(9, 'Initech')];
        $templates = [1 => new TemplateEntity(1, 'Welcome'), 2 => new TemplateEntity(2, 'Reminder'), 3 => new TemplateEntity(3, 'Survey')];
        $campaigns = [
            self::SPRING => new CampaignEntity(self::SPRING, 'Spring', $advertisers[4]),
            self::SUMMER => new CampaignEntity(self::SUMMER, 'Summer', $advertisers[7]),
            self::AUTUMN => new CampaignEntity(self::AUTUMN, 'Autumn', $advertisers[9]),
        ];

        $leads = [];
        foreach ([
            [1, 'Ada', 'open', self::SPRING, [1, 2]],
            [2, 'Grace', 'open', self::SUMMER, [2]],
            [3, 'Hedy', 'open', self::AUTUMN, []],
            [4, 'Joan', 'closed', self::SPRING, [3]],
            [5, 'Katherine', 'open', null, [1]],
        ] as [$id, $name, $status, $campaign, $templateIds]) {
            $lead = new LeadEntity($id, $name, $status, $campaign !== null ? $campaigns[$campaign] : null);
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
