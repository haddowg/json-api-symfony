<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Operation\Operation;

#[AsJsonApiResource(server: 'partner', operations: [Operation::FetchOne, Operation::Update])]
final class InMemoryPartnerScopingMixedAdvertiserResource extends BaseScopingMixedAdvertiserResource
{
    protected const string PREFIX = 'partner-';

    public static string $type = 'partner-mixed-advertisers';
}
