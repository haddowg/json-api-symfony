<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Operation\Operation;

#[AsJsonApiResource(entity: ScopingAdvertiser::class, operations: [Operation::FetchCollection, Operation::FetchOne, Operation::Update])]
final class ProviderDefaultScopingAdvertiserResource extends BaseScopingAdvertiserResource
{
    public static string $type = 'advertisers';
}
