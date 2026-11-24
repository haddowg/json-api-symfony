<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;

/**
 * Stores a {@see Code} as an integer, so its database form differs from both its PHP
 * value object and its `c-<n>` wire form. It converts only a `Code` to the database,
 * as a strict custom type does, and reads back an integer or either string form.
 */
final class CodeType extends Type
{
    public const string NAME = 'im_code';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getIntegerTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof Code) {
            throw new ConversionException('A Code column stores only a Code.');
        }

        return $value->value;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Code
    {
        if ($value === null) {
            return null;
        }

        return Code::parse(\is_string($value) && \ctype_digit($value) ? (int) $value : $value)
            ?? throw new ConversionException('Not a Code.');
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
