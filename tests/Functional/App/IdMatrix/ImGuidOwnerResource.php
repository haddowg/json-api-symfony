<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

abstract class ImGuidOwnerResource extends ImOwnerResource
{
    public static string $type = 'im-guid-owners';

    protected const string FOREIGN_KEY = 'guidOwnerId';
}
