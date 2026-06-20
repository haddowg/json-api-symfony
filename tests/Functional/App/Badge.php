<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * The in-memory domain object for the request-aware-predicates conformance suite:
 * a `badges` POPO carrying the attributes the predicate fixture renders/hydrates
 * (`name`, `secret`, `writeOnlySecret`, `rank`, `clearance`) and a to-many `medals`
 * collection the gated relationship endpoints mutate. It mirrors the
 * {@see Doctrine\BadgeEntity} field-for-field so the same conformance assertions
 * run on both providers.
 *
 * @phpstan-type MedalList list<Medal>
 */
final class Badge
{
    /**
     * @param list<Medal> $medals
     */
    public function __construct(
        public ?int $id = null,
        public string $name = '',
        public ?string $secret = null,
        public ?string $writeOnlySecret = null,
        public ?string $rank = null,
        public ?string $clearance = null,
        public array $medals = [],
    ) {}
}
