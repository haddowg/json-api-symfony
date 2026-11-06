<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Deprecation;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true, deprecation: true)]
final class InMemoryMemoResource extends PlainResource
{
    public static string $type = 'memos';
}
