<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Uid\Uuid;

/**
 * A prefix-style encoder: the wire id is `own_` and the uuid's canonical string, and a
 * decoded id is the `Uuid` the entity holds.
 */
final class PrefixUuidEncoder implements IdEncoderInterface
{
    public function encode(mixed $storageKey): string
    {
        $uuid = match (true) {
            $storageKey instanceof AbstractUid => $storageKey,
            \is_string($storageKey) && Uuid::isValid($storageKey) => Uuid::fromString($storageKey),
            \is_string($storageKey) && \strlen($storageKey) === 16 => Uuid::fromBinary($storageKey),
            default => throw new \InvalidArgumentException('Not a uuid.'),
        };

        return 'own_' . $uuid->toRfc4122();
    }

    public function decode(string $wireId): ?Uuid
    {
        $uuid = \substr($wireId, 4);

        return \str_starts_with($wireId, 'own_') && Uuid::isValid($uuid) ? Uuid::fromString($uuid) : null;
    }
}
