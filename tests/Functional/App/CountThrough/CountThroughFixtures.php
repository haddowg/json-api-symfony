<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\CountThrough;

use haddowg\JsonApiBundle\Tests\Functional\App\CountThrough\Doctrine\BrandEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\CountThrough\Doctrine\CampaignEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\CountThrough\Doctrine\LeadEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\CountThrough\Doctrine\TemplateEntity;

/**
 * The campaign graph both kernels serve, the Doctrine one after persisting it.
 *
 * Campaigns and leads both have a `brand`, so a filter correlated to the campaign
 * instead of the lead reads the campaign's brand and counts the wrong leads.
 *
 * Brands 1 Acme and 2 Globex. Templates 1 Welcome and 2 Reminder. Campaigns 1 Spring
 * for Acme, 2 Summer for Globex and 3 Autumn with no brand. Leads:
 *
 *  - 1 Ada, Spring, Globex, Welcome
 *  - 2 Grace, Spring, Acme, Welcome and Reminder
 *  - 3 Hedy, Spring, no brand, no template
 *  - 4 Joan, Summer, Globex, Reminder
 *  - 5 Katherine, Autumn, Acme, Welcome
 *  - 6 Lin, Autumn, no brand, no template
 *
 * Spring shortlists leads 2, 4 and 5, Summer lead 1, and Autumn none.
 */
final class CountThroughFixtures
{
    /**
     * @return array{brands: list<BrandEntity>, templates: list<TemplateEntity>, campaigns: list<CampaignEntity>, leads: list<LeadEntity>}
     */
    public static function graph(): array
    {
        $brands = [1 => new BrandEntity(1, 'Acme'), 2 => new BrandEntity(2, 'Globex')];
        $templates = [1 => new TemplateEntity(1, 'Welcome'), 2 => new TemplateEntity(2, 'Reminder')];
        $campaigns = [
            1 => new CampaignEntity(1, 'Spring', $brands[1]),
            2 => new CampaignEntity(2, 'Summer', $brands[2]),
            3 => new CampaignEntity(3, 'Autumn'),
        ];

        $leads = [];
        foreach ([
            [1, 'Ada', 1, 2, [1], [2]],
            [2, 'Grace', 1, 1, [1, 2], [1]],
            [3, 'Hedy', 1, null, [], []],
            [4, 'Joan', 2, 2, [2], [1]],
            [5, 'Katherine', 3, 1, [1], [1]],
            [6, 'Lin', 3, null, [], []],
        ] as [$id, $name, $campaign, $brand, $templateIds, $shortlistedBy]) {
            $lead = new LeadEntity($id, $name, $campaigns[$campaign], $brand !== null ? $brands[$brand] : null);
            foreach ($templateIds as $templateId) {
                $lead->templates->add($templates[$templateId]);
            }
            $campaigns[$campaign]->leads->add($lead);
            foreach ($shortlistedBy as $shortlister) {
                $campaigns[$shortlister]->shortlist->add($lead);
            }
            $leads[] = $lead;
        }

        return [
            'brands' => \array_values($brands),
            'templates' => \array_values($templates),
            'campaigns' => \array_values($campaigns),
            'leads' => $leads,
        ];
    }
}
