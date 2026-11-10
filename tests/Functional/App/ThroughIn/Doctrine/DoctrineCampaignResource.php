<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\BaseCampaignResource;

#[AsJsonApiResource(entity: CampaignEntity::class, readOnly: true)]
final class DoctrineCampaignResource extends BaseCampaignResource {}
