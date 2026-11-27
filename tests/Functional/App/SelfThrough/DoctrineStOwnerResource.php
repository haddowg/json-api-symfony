<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SelfThrough;

final class DoctrineStOwnerResource extends StOwnerResource
{
    public static string $type = 'st-owners';

    protected const ?string SEAT = StSeat::class;
}
