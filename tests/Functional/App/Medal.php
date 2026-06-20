<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * The far (related) `medals` type of the request-aware-predicates fixture: a plain
 * id/title object the to-many `badges.medals` relation links to. Mirrors
 * {@see Doctrine\MedalEntity} so the relationship-mutation and include assertions
 * run identically on both providers.
 *
 * It carries a read-only inverse `badges` back-reference so a badge can also be
 * rendered as an INCLUDED resource (`GET /medals/1?include=badges`) and as the
 * primary of a RELATED read (`GET /medals/1/badges`) — the contexts the
 * hidden-`secret` negative assertion exercises beyond the single/collection badge read.
 */
final class Medal
{
    /**
     * @param list<Badge> $badges
     */
    public function __construct(
        public ?int $id = null,
        public string $title = '',
        public array $badges = [],
    ) {}
}
