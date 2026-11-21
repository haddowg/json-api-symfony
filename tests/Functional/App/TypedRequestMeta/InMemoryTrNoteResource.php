<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(securityUpdate: "object.title != 'Broken'", securityDelete: "object.title != 'Plan'")]
final class InMemoryTrNoteResource extends TrNoteResource {}
