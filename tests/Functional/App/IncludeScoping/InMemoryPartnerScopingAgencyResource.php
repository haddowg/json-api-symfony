<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(server: 'partner', readOnly: true)]
final class InMemoryPartnerScopingAgencyResource extends BaseScopingAgencyResource
{
    protected const string PREFIX = 'partner-';

    public static string $type = 'partner-agencies';
}
