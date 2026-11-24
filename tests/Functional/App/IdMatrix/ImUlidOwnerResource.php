<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

abstract class ImUlidOwnerResource extends ImOwnerResource
{
    public static string $type = 'im-ulid-owners';

    protected const string FOREIGN_KEY = 'ulidOwnerId';
}
