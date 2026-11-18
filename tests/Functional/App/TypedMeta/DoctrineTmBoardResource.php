<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedMeta;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: TmBoard::class, readOnly: true)]
final class DoctrineTmBoardResource extends TmBoardResource {}
