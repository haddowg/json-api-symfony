<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MethodNotAllowed;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: Advertiser::class)]
final class DoctrineAdvertiserResource extends AdvertiserResource {}
