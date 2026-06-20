<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseFlattenBookResource;

/**
 * The Doctrine `books` resource of the flattened-attribute (`on()`) fixture (bundle
 * ADR 0085): {@see BaseFlattenBookResource} mapped to {@see FlattenBookEntity} via
 * `#[AsJsonApiResource(entity: …)]`, served by the bundle's `-128` fallback Doctrine
 * provider/persister. The flattened `authorName` read/write, the computed `display`,
 * and the `author`/`publisher` eager loads must behave identically to the in-memory
 * witness, proving they are provider-agnostic.
 */
#[AsJsonApiResource(entity: FlattenBookEntity::class)]
final class DoctrineFlattenBookResource extends BaseFlattenBookResource {}
