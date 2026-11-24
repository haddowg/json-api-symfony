<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;

abstract class ImPrefixOwnerResource extends ImOwnerResource
{
    public static string $type = 'im-prefix-owners';

    protected const string FOREIGN_KEY = 'prefixOwnerId';

    protected function encoder(): ?IdEncoderInterface
    {
        return new PrefixUuidEncoder();
    }
}
