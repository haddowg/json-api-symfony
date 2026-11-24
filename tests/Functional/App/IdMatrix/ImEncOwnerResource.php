<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;

abstract class ImEncOwnerResource extends ImOwnerResource
{
    public static string $type = 'im-enc-owners';

    protected const string FOREIGN_KEY = 'encOwnerId';

    protected function encoder(): ?IdEncoderInterface
    {
        return new \haddowg\JsonApiBundle\Tests\Functional\App\BinaryUuid\BinaryUuidEncoder();
    }
}
