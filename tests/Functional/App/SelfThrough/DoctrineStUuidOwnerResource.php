<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SelfThrough;

final class DoctrineStUuidOwnerResource extends StOwnerResource
{
    public static string $type = 'st-uuid-owners';

    protected const ?string SEAT = StUuidSeat::class;

    protected const bool ENCODED = true;
}
