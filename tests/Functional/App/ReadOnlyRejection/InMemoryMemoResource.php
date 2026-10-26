<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ReadOnlyRejection;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(server: ['default', 'strict'])]
final class InMemoryMemoResource extends MemoResource {}
