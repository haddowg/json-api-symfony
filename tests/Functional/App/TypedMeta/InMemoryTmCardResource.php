<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedMeta;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(delete: [new MetaResult()])]
final class InMemoryTmCardResource extends TmCardResource {}
