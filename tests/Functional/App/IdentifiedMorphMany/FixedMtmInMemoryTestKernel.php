<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany;

class FixedMtmInMemoryTestKernel extends MtmInMemoryTestKernel
{
    protected function name(): string
    {
        return 'fixed-membership-morph-many-inmemory';
    }

    protected function userResource(): string
    {
        return FixedMtmUserResource::class;
    }
}
