<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BasePublicMemberResource;

/**
 * The Doctrine kernel's `public-members` resource (the CURATED view), mapped to the
 * SAME {@see MemberEntity} {@see DoctrineMemberResource} (`members`) maps — the
 * second JSON:API type backed by the one entity. Both contribute the same
 * `public-members`/`members` → MemberEntity map entry through the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\DoctrineEntityMapPass}.
 */
#[AsJsonApiResource(entity: MemberEntity::class)]
final class DoctrinePublicMemberResource extends BasePublicMemberResource {}
