<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Inbox\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Inbox\BaseInboxCampaignResource;

#[AsJsonApiResource(entity: InboxCampaignEntity::class, readOnly: true)]
final class DoctrineInboxCampaignResource extends BaseInboxCampaignResource {}
