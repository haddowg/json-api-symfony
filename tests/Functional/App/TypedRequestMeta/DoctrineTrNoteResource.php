<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: TrNote::class, securityUpdate: "object.title != 'Broken'")]
final class DoctrineTrNoteResource extends TrNoteResource {}
