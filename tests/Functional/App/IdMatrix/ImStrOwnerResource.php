<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

abstract class ImStrOwnerResource extends ImOwnerResource
{
    public static string $type = 'im-str-owners';

    protected const string FOREIGN_KEY = 'strOwnerId';
}
