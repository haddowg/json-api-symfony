<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Include;

/**
 * A plain in-memory model for the include-safeguards suite: a `nodes` resource
 * wired into a circular `next` chain (n1 → n2 → n3 → n1) so that default-including
 * `next` would recurse forever without a depth cap — the mutual/self default-include
 * cycle the bundle's {@see \haddowg\JsonApiBundle\JsonApiBundle::DEFAULT_MAX_INCLUDE_DEPTH}
 * must terminate (bundle ADR 0037).
 *
 * `prev` is the back-reference the {@see \haddowg\JsonApiBundle\Tests\Functional\App\Include\Resource\NodeResource}
 * marks `cannotBeIncluded()` (Capability A); `tag` is a to-one whose related
 * `tags` resource is the witness that a relation includable from its own root can
 * still be forbidden as a nested path by a parent's allowed-include-paths whitelist
 * (Capability C).
 */
final class Node
{
    public function __construct(
        public string $id = '',
        public string $label = '',
        public ?Node $next = null,
        public ?Node $prev = null,
        public ?Tag $tag = null,
    ) {}
}
