<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Served by the `tokyo` server, whose own output timezone overrides the top-level one.
 */
#[AsJsonApiResource(server: 'tokyo', readOnly: true)]
final class GigListingResource extends BaseGigStartResource
{
    public static string $type = 'gig-listings';
}
