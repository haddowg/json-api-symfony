<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

/**
 * The in-memory kernel's `members` resource (the FULL view): the shared
 * {@see BaseMemberResource} declaration served by an
 * {@see \haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider} over the Member
 * objects. The curated twin is {@see PublicMemberResource} (`public-members`),
 * reading the SAME objects through a sibling provider.
 */
final class MemberResource extends BaseMemberResource {}
