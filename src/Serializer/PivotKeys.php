<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApi\Resource\Field\StoredIdForms;

/**
 * Finds an entry of a pivot map by the wire id a resource renders under. A provider keys
 * its map by the wire id or by the id it stores, and either may hold a uuid-shaped id in
 * capitals, so the lookup tries the wire id, then its comparable form, then the stored id
 * the type's encoder decodes it to.
 *
 * @internal
 */
final class PivotKeys
{
    /**
     * @template T
     *
     * @param array<array-key, T> $map
     *
     * @return T|null
     */
    public static function find(array $map, string $wireId, ?IdEncoderInterface $encoder): mixed
    {
        if (\array_key_exists($wireId, $map)) {
            return $map[$wireId];
        }

        $wanted = [StoredIdForms::comparable($wireId) => true];
        try {
            $stored = $encoder?->decode($wireId);
        } catch (\Throwable) {
            $stored = null;
        }
        if (\is_string($stored) || \is_int($stored) || $stored instanceof \Stringable) {
            $wanted[StoredIdForms::comparable($stored)] = true;
        }

        foreach ($map as $key => $value) {
            if (isset($wanted[StoredIdForms::comparable($key)])) {
                return $value;
            }
        }

        return null;
    }
}
