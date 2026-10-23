<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Operation\Operation;

#[AsJsonApiResource(entity: ScopingAdvertiser::class, operations: [Operation::FetchOne, Operation::Update])]
final class DefaultScopingMixedAdvertiserResource extends BaseScopingMixedAdvertiserResource
{
    public static string $type = 'mixed-advertisers';
}
