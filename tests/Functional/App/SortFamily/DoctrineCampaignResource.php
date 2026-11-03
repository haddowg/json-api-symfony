<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SortFamily;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: CampaignEntity::class, readOnly: true)]
final class DoctrineCampaignResource extends BaseCampaignResource {}
