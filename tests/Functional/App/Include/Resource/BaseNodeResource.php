<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Include\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The shared `nodes` declaration both include-safeguard kernels serve (in-memory
 * and Doctrine-sqlite). A node is wired into a circular `next` chain
 * (n1 → n2 → n3 → n1), and `next` is **default-included**, so the default cascade
 * would recurse forever without the bundle's max-include-depth cap — the cycle the
 * cap must terminate (bundle ADR 0037).
 *
 * `prev` is the back-reference marked `cannotBeIncluded()` (Capability A): a
 * `?include` naming it is a `400`, and the default cascade never auto-includes it.
 * `tag` reaches the `tags` type, the witness that a relation includable from its
 * own root can still be forbidden as a nested path by a parent's
 * allowed-include-paths whitelist (Capability C).
 */
abstract class BaseNodeResource extends AbstractResource
{
    public static string $type = 'nodes';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
            // The circular forward chain, default-included so an uncapped render
            // would recurse n1 → n2 → n3 → n1 → … forever.
            BelongsTo::make('next')->type('nodes'),
            // The back-reference: opted out of inclusion (Capability A).
            BelongsTo::make('prev')->type('nodes')->cannotBeIncluded(),
            // A to-one into `tags`, includable from `nodes`' own root.
            BelongsTo::make('tag')->type('tags'),
        ];
    }

    /**
     * `next` is default-included, so a plain `GET /nodes/{id}` (no `?include`)
     * walks the circular chain — bounded only by the effective max include depth.
     */
    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return ['next'];
    }
}
