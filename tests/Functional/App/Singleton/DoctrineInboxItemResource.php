<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Singleton;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: InboxItem::class, readOnly: true)]
final class DoctrineInboxItemResource extends InboxItemResource {}
