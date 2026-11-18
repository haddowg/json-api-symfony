<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphGate;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: GatedChannel::class, readOnly: true)]
final class DoctrineGatedChannelResource extends GatedChannelResource {}
