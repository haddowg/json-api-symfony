<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

abstract class ImIntOwnerResource extends ImOwnerResource
{
    public static string $type = 'im-int-owners';

    protected const string FOREIGN_KEY = 'intOwnerId';
}
