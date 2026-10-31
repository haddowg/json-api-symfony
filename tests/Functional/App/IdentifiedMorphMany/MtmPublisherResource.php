<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany;

final class MtmPublisherResource extends MtmCompanyResource
{
    public static string $type = 'publishers';
}
