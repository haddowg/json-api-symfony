<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;

/**
 * A gig's start time alone, served by the named servers of the output-timezone
 * kernels so each server's own zone is visible on the same stored value.
 */
abstract class BaseGigStartResource extends AbstractResource
{
    public function fields(): array
    {
        return [
            Id::make(),
            DateTime::make('startsAt'),
        ];
    }
}
