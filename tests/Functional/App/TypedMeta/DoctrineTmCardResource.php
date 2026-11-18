<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedMeta;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: TmCard::class, delete: [new MetaResult()])]
final class DoctrineTmCardResource extends TmCardResource {}
