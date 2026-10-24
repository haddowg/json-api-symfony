<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

/**
 * A related resource a to-many write would move to a new parent: the {@see $member}
 * and the parent it currently belongs to ({@see $from}, `null` when it belongs to
 * none). Reported by {@see ReportsMovedMembersInterface}.
 */
final readonly class MovedMember
{
    public function __construct(
        public object $member,
        public ?object $from,
    ) {}
}
