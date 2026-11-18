<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Operation\Operation;

#[AsJsonApiResource(operations: [Operation::FetchCollection, Operation::FetchOne, Operation::Update])]
final class InMemoryFkTeamResource extends FkTeamResource {}
