<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\LoadedLinkage;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: LinkageBinding::class, readOnly: true)]
final class DoctrineBindingResource extends BaseBindingResource {}
