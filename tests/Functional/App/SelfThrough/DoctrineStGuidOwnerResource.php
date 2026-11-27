<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SelfThrough;

final class DoctrineStGuidOwnerResource extends StOwnerResource
{
    public static string $type = 'st-guid-owners';

    protected const ?string SEAT = StGuidSeat::class;
}
