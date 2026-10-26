<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EntityHooks;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: HookedNote::class)]
final class DoctrineHookedNoteResource extends HookedNoteResource {}
