<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Inbox\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Inbox\BaseInboxItemResource;

#[AsJsonApiResource(entity: InboxItemEntity::class, readOnly: true)]
final class DoctrineInboxItemResource extends BaseInboxItemResource {}
