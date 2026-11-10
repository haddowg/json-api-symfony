<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\BaseTemplateResource;

#[AsJsonApiResource(entity: TemplateEntity::class, readOnly: true)]
final class DoctrineTemplateResource extends BaseTemplateResource {}
