<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Operation\Operation;

#[AsJsonApiResource(entity: ScopingAgency::class, operations: [Operation::FetchCollection, Operation::FetchOne, Operation::Update])]
final class DefaultScopingAgencyResource extends BaseScopingAgencyResource
{
    public static string $type = 'agencies';
}
