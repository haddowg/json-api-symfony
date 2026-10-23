<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Operation\Operation;

#[AsJsonApiResource(entity: ScopingMember::class, server: 'partner', operations: [Operation::FetchCollection, Operation::FetchOne, Operation::Update])]
final class PartnerScopingMemberResource extends BaseScopingMemberResource
{
    protected const string PREFIX = 'partner-';

    public static string $type = 'partner-members';
}
