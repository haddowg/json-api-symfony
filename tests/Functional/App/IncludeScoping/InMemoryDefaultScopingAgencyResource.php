<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true)]
final class InMemoryDefaultScopingAgencyResource extends BaseScopingAgencyResource
{
    public static string $type = 'agencies';
}
