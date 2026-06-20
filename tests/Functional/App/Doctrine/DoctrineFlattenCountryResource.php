<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseFlattenCountryResource;

/**
 * The Doctrine `countries` resource of the flattened-attribute (`on()`) fixture
 * (bundle ADR 0085): {@see BaseFlattenCountryResource} mapped to
 * {@see FlattenCountryEntity}. The SECOND level the book's nested `author.country`
 * pin walks to — eager-loaded level-by-level but never rendered as a relationship.
 */
#[AsJsonApiResource(entity: FlattenCountryEntity::class)]
final class DoctrineFlattenCountryResource extends BaseFlattenCountryResource {}
