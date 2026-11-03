<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SortFamily;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

final class CampaignProviderFactory
{
    public static function create(): InMemoryDataProvider
    {
        $campaigns = [];
        foreach (CampaignFixtures::data() as $id => $row) {
            $campaigns[(string) $id] = new Campaign($id, $row['name'], $row['values']);
        }

        return new InMemoryDataProvider('campaigns', $campaigns, sortArms: [new InMemoryPlaceholderValueSortArm()]);
    }
}
