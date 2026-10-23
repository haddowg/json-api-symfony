<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true)]
final class InMemoryDefaultScopingAdvertiserResource extends BaseScopingAdvertiserResource
{
    public static string $type = 'advertisers';
}
