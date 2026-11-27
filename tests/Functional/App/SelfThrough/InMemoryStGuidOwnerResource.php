<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SelfThrough;

final class InMemoryStGuidOwnerResource extends StOwnerResource
{
    public static string $type = 'st-guid-owners';

}
