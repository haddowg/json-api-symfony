<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

/**
 * The in-memory `badges` resource: the request-aware-predicate fixture
 * ({@see BaseBadgeResource}) served over the {@see \haddowg\JsonApiBundle\Tests\Functional\App\Badge}
 * POPO graph by an in-memory provider/persister.
 */
final class BadgeResource extends BaseBadgeResource {}
