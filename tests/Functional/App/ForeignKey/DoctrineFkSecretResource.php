<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: FkSecret::class, readOnly: true)]
final class DoctrineFkSecretResource extends FkSecretResource {}
