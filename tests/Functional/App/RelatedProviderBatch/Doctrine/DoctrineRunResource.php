<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedProviderBatch\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\RelatedProviderBatch\BaseRunResource;

#[AsJsonApiResource(entity: RunEntity::class, readOnly: true)]
final class DoctrineRunResource extends BaseRunResource {}
