<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

/**
 * The in-memory kernel's `public-members` resource (the CURATED view): the shared
 * {@see BasePublicMemberResource} declaration served by a SECOND
 * {@see \haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider} reading the SAME
 * Member objects the full {@see MemberResource} (`members`) serves — one entity,
 * two types.
 */
final class PublicMemberResource extends BasePublicMemberResource {}
