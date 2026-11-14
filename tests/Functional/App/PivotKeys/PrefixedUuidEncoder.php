<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotKeys;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Renders a UUID id behind a `def-` prefix.
 */
final class PrefixedUuidEncoder implements IdEncoderInterface
{
    private const string PREFIX = 'def-';

    public function encode(mixed $storageKey): string
    {
        return self::PREFIX . ($storageKey instanceof Uuid ? $storageKey->toRfc4122() : (\is_string($storageKey) && Uuid::isValid($storageKey) ? Uuid::fromString($storageKey)->toRfc4122() : ''));
    }

    public function decode(string $wireId): ?string
    {
        $uuid = \substr($wireId, \strlen(self::PREFIX));

        return \str_starts_with($wireId, self::PREFIX) && Uuid::isValid($uuid) ? $uuid : null;
    }
}
