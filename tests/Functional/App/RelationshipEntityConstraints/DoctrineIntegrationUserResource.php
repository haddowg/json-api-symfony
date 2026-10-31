<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationshipEntityConstraints;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: IntegrationUser::class)]
final class DoctrineIntegrationUserResource extends IntegrationUserResource {}
