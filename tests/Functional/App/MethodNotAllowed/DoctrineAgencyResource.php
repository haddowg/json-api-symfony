<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MethodNotAllowed;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: Agency::class, readOnly: true)]
final class DoctrineAgencyResource extends AgencyResource {}
