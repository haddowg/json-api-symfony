<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseFlattenPublisherResource;

/**
 * The Doctrine `publishers` resource of the flattened-attribute (`on()`) fixture
 * (bundle ADR 0085): {@see BaseFlattenPublisherResource} mapped to
 * {@see FlattenPublisherEntity}. A sibling registered type — the book carries a
 * `publisher` FK but no longer flattens or eager-pins it.
 */
#[AsJsonApiResource(entity: FlattenPublisherEntity::class)]
final class DoctrineFlattenPublisherResource extends BaseFlattenPublisherResource {}
