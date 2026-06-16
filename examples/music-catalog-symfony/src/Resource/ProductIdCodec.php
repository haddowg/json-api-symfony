<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;

/**
 * A small, dependency-free, reversible id codec demonstrating that the JSON:API
 * `id` a client sees can be the **wire** form of a distinct **storage** key — here
 * the auto-increment integer primary key of the {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Product Product}
 * entity is never exposed; clients see an opaque `prod-…` token instead (bundle
 * ADR 0038, à la Laravel JSON:API custom id encoding).
 *
 * The transform XORs each byte of the decimal storage key with a fixed key stream
 * and hex-encodes the result behind a `prod-` prefix, so it is fully reversible
 * without a dependency (a production app would more likely reach for `symfony/uid`
 * binary UUIDs or `hashids`). {@see decode()} returns `null` for anything that is
 * not a well-formed token, which the Doctrine layer treats as a `404` (read) /
 * bad linkage target (write).
 *
 * The boundary the bundle owns: core encodes the stored key on serialize and
 * decodes a client-generated id on create (the entity always holds the storage
 * key); the Doctrine provider/persister decode the route `{id}` and linkage ids
 * (which arrive as wire strings through the storage-agnostic SPI) before the
 * lookup / `getReference`.
 */
final class ProductIdCodec implements IdEncoderInterface
{
    private const string PREFIX = 'prod-';

    /**
     * The repeating XOR key stream — any non-empty byte string obfuscates the
     * decimal digits so the wire token is visibly unrelated to the storage key.
     */
    private const string KEY = "\x4a\x37\x9e\x21";

    public function encode(mixed $storageKey): string
    {
        $key = \is_scalar($storageKey) ? (string) $storageKey : '';

        return self::PREFIX . \bin2hex(self::xor($key));
    }

    public function decode(string $wireId): mixed
    {
        if (!\str_starts_with($wireId, self::PREFIX)) {
            return null;
        }

        $hex = \substr($wireId, \strlen(self::PREFIX));
        if ($hex === '' || (\strlen($hex) % 2) !== 0 || \preg_match('/^[0-9a-f]+$/', $hex) !== 1) {
            return null;
        }

        $bytes = \hex2bin($hex);
        if ($bytes === false) {
            return null;
        }

        $decoded = self::xor($bytes);

        // The storage key is an integer primary key; a token that does not decode
        // to a non-negative integer string is not one this codec ever produced.
        return \preg_match('/^[0-9]+$/', $decoded) === 1 ? $decoded : null;
    }

    /**
     * The symmetric XOR transform (its own inverse) over the repeating key stream.
     */
    private static function xor(string $value): string
    {
        $key = self::KEY;
        $keyLength = \strlen($key);
        $out = '';
        for ($i = 0, $length = \strlen($value); $i < $length; $i++) {
            $out .= $value[$i] ^ $key[$i % $keyLength];
        }

        return $out;
    }
}
