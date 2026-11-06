<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Deprecation;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(server: 'plain', readOnly: true)]
final class InMemoryNoticeResource extends PlainResource
{
    public static string $type = 'notices';
}
