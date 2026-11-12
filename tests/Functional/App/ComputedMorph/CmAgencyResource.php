<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedMorph;

/**
 * Agencies count their `peers`; advertisers declare the same relation uncounted.
 */
final class CmAgencyResource extends CmCompanyResource
{
    public static string $type = 'cm-agencies';

    protected function relations(): array
    {
        return [$this->peers()->countable()];
    }
}
