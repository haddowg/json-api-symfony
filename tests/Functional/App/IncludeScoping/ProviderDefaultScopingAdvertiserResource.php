<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: ScopingAdvertiser::class, readOnly: true)]
final class ProviderDefaultScopingAdvertiserResource extends BaseScopingAdvertiserResource
{
    public static string $type = 'advertisers';
}
