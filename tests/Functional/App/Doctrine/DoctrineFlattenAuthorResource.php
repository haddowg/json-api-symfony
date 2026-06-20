<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseFlattenAuthorResource;

/**
 * The Doctrine `authors` resource of the flattened-attribute (`on()`) fixture
 * (bundle ADR 0085): {@see BaseFlattenAuthorResource} mapped to
 * {@see FlattenAuthorEntity}. Re-fetching `/authors/{id}` after a flattened
 * `authorName` PATCH is the witness that Doctrine's unit of work auto-persisted the
 * dirty loaded author.
 */
#[AsJsonApiResource(entity: FlattenAuthorEntity::class)]
final class DoctrineFlattenAuthorResource extends BaseFlattenAuthorResource {}
