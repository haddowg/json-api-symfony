<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\BaseAdvertiserResource;

#[AsJsonApiResource(entity: AdvertiserEntity::class, readOnly: true)]
final class DoctrineAdvertiserResource extends BaseAdvertiserResource {}
