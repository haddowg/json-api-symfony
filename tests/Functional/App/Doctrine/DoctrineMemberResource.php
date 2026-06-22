<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseMemberResource;

/**
 * The Doctrine kernel's `members` resource (the FULL view), mapped to
 * {@see MemberEntity}. Its curated sibling {@see DoctrinePublicMemberResource}
 * (`public-members`) maps the SAME entity — two JSON:API types, one entity — which
 * the {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\DoctrineEntityMapPass}
 * accepts (it only rejects one type mapping to two entities).
 */
#[AsJsonApiResource(entity: MemberEntity::class)]
final class DoctrineMemberResource extends BaseMemberResource {}
