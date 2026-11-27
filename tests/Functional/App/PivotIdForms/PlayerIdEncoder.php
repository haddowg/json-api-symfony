<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotIdForms;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;

/**
 * Renders a player's key behind a `pl-` prefix, in the case it is given.
 */
final class PlayerIdEncoder implements IdEncoderInterface
{
    private const string PREFIX = 'pl-';

    public function encode(mixed $storageKey): string
    {
        return self::PREFIX . (\is_scalar($storageKey) || $storageKey instanceof \Stringable ? (string) $storageKey : '');
    }

    public function decode(string $wireId): ?string
    {
        return \str_starts_with($wireId, self::PREFIX) ? \substr($wireId, \strlen(self::PREFIX)) : null;
    }
}
