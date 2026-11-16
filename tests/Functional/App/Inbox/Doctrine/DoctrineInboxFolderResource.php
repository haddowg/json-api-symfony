<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Inbox\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Inbox\BaseInboxFolderResource;

#[AsJsonApiResource(entity: InboxFolderEntity::class, readOnly: true)]
final class DoctrineInboxFolderResource extends BaseInboxFolderResource {}
