<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedMorph;

final class CmAgencyResource extends CmCompanyResource
{
    public static string $type = 'cm-agencies';
}
