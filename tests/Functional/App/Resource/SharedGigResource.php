<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(server: ['default', 'tokyo', 'raw'], readOnly: true)]
final class SharedGigResource extends BaseSharedGigResource {}
