<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\CountThrough\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\CountThrough\BaseLeadResource;

#[AsJsonApiResource(entity: LeadEntity::class, readOnly: true)]
final class DoctrineLeadResource extends BaseLeadResource {}
