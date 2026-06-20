<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseStickerResource;

/**
 * The Doctrine far (related) `stickers` resource ({@see BaseStickerResource}) mapped
 * to {@see StickerEntity}, served by the bundle's `-128` fallback Doctrine provider.
 */
#[AsJsonApiResource(entity: StickerEntity::class)]
final class DoctrineStickerResource extends BaseStickerResource {}
