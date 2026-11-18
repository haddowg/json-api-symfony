<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphGate;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: GatedVideo::class, readOnly: true, securityRead: "object.name != 'classified'")]
final class DoctrineGatedVideoResource extends GatedVideoResource {}
