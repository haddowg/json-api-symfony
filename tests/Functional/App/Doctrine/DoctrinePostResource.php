<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BasePostResource;

/**
 * The Doctrine kernel's `posts` resource, mapped to {@see PostEntity}. Its `author`
 * relation targets the curated `public-members` type (declared on
 * {@see BasePostResource}); the Doctrine provider resolves that type to
 * {@see MemberEntity} via the same type→entity map, reading the `author` association
 * off the post — so the relationship/related/include all render the curated view.
 */
#[AsJsonApiResource(entity: PostEntity::class)]
final class DoctrinePostResource extends BasePostResource {}
