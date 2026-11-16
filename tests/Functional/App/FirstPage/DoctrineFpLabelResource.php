<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FirstPage;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: FpLabel::class)]
final class DoctrineFpLabelResource extends FpLabelResource {}
