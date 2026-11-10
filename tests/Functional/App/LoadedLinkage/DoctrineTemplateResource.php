<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\LoadedLinkage;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: LinkageTemplate::class, readOnly: true)]
final class DoctrineTemplateResource extends BaseTemplateResource {}
