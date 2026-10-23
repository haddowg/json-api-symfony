<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: ScopingAgency::class, readOnly: true)]
final class DefaultScopingAgencyResource extends BaseScopingAgencyResource
{
    public static string $type = 'agencies';
}
