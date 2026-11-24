<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

abstract class ImCodeOwnerResource extends ImOwnerResource
{
    public static string $type = 'im-code-owners';

    protected const string FOREIGN_KEY = 'codeOwnerId';
}
