<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\BaseTxNoteResource;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\TxNote;

#[AsJsonApiResource(entity: TxNote::class)]
final class TxNoteResource extends BaseTxNoteResource {}
