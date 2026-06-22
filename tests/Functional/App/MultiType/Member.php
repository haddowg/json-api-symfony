<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MultiType;

/**
 * A plain domain model fed to the in-memory provider for the
 * **multi-type-per-entity** conformance suite. One object is rendered as TWO
 * JSON:API types: the full `members` type (every property) and the curated
 * `public-members` type (display name only). The private `email`/`secretNote`
 * are deliberately omitted from the curated view's field inventory, never via a
 * runtime filter — so the same record presents a strictly narrower projection
 * under one type than the other.
 *
 * The Doctrine twin is
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\MemberEntity}.
 */
final class Member
{
    public function __construct(
        public ?int $id = null,
        public string $displayName = '',
        public string $email = '',
        public string $secretNote = '',
    ) {}
}
