<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany;

class FixedMtmDoctrineTestKernel extends MtmDoctrineTestKernel
{
    protected function name(): string
    {
        return 'fixed-membership-morph-many-doctrine';
    }

    protected function userResource(): string
    {
        return FixedMtmUserResource::class;
    }
}
