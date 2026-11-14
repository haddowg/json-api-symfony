<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;

/**
 * Renders an integer storage key plus 100, so a second type over the cog entity has
 * wire ids no other encoder of that entity reads the same way.
 */
final class OffsetIdEncoder implements IdEncoderInterface
{
    public function encode(mixed $storageKey): string
    {
        return (string) ((\is_numeric($storageKey) ? (int) $storageKey : 0) + 100);
    }

    public function decode(string $wireId): ?int
    {
        return \preg_match('/^[0-9]+$/', $wireId) === 1 && (int) $wireId > 100 ? (int) $wireId - 100 : null;
    }
}
