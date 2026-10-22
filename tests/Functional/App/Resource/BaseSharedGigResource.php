<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;

/**
 * One type served by every server of the output-timezone kernels, each with its own
 * zone, so a resource instance shared between servers would show up as one server's
 * zone on another's response. The `venue` relation checks the same for an included
 * resource.
 */
abstract class BaseSharedGigResource extends AbstractResource
{
    public static string $type = 'shared-gigs';

    public function fields(): array
    {
        return [
            Id::make(),
            DateTime::make('startsAt'),
            BelongsTo::make('venue', 'venues'),
        ];
    }
}
