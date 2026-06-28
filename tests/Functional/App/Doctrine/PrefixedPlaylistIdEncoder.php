<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;

/**
 * A trivial reversible id codec for the multi-type-backed pivot-parent witness
 * (bundle ADR 0102): {@see PlaylistEntity} backs BOTH the plain `playlists` type
 * (no encoder) AND the {@see EncodedPlaylistResource} `encoded-playlists` type,
 * which attaches THIS encoder so its wire id is a `pl-`-prefixed token distinct
 * from the bare stringified storage id.
 *
 * It exists to prove the batched primary-document pivot map keys its outer entry by
 * the SERVED type's encoder, not one reverse-resolved from the entity class (which
 * would pick the first-registered `playlists` type's no-encoder and diverge from
 * this type's `getId()`, silently dropping the pivot on the primary linkage).
 */
final class PrefixedPlaylistIdEncoder implements IdEncoderInterface
{
    public const string PREFIX = 'pl-';

    public function encode(mixed $storageKey): string
    {
        $key = \is_scalar($storageKey) ? (string) $storageKey : '';

        return self::PREFIX . $key;
    }

    public function decode(string $wireId): ?int
    {
        if (!\str_starts_with($wireId, self::PREFIX)) {
            return null;
        }

        $key = \substr($wireId, \strlen(self::PREFIX));
        if (\preg_match('/^[0-9]+$/', $key) !== 1) {
            return null;
        }

        return (int) $key;
    }
}
