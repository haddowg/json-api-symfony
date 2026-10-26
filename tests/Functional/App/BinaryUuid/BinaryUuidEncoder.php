<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\BinaryUuid;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Uid\Uuid;

/**
 * Sends a binary `uuid` id as its RFC 4122 string and decodes the wire id to the
 * 16-byte form, the shape applications wrote before the Doctrine layer bound ids
 * through their type.
 */
final class BinaryUuidEncoder implements IdEncoderInterface
{
    public function encode(mixed $storageKey): string
    {
        if ($storageKey instanceof AbstractUid) {
            return $storageKey->toRfc4122();
        }

        return Uuid::fromBinary(\is_string($storageKey) ? $storageKey : '')->toRfc4122();
    }

    public function decode(string $wireId): ?string
    {
        return Uuid::isValid($wireId) ? Uuid::fromString($wireId)->toBinary() : null;
    }
}
