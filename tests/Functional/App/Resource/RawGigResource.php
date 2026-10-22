<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Served by the `raw` server, which sets its output timezone to `''` and so renders
 * each value in the zone it carries.
 */
#[AsJsonApiResource(server: 'raw', readOnly: true)]
final class RawGigResource extends BaseGigStartResource
{
    public static string $type = 'raw-gigs';
}
