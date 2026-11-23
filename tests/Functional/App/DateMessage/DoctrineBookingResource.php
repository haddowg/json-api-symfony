<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DateMessage;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: BookingEntity::class)]
final class DoctrineBookingResource extends BaseBookingResource {}
