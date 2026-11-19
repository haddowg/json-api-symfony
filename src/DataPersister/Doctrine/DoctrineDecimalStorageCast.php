<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister\Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Mapping\MappingException as PersistenceMappingException;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\StorageCastInterface;

/**
 * Hands a Doctrine `decimal` column the string Doctrine reads it as. A number written to
 * one becomes a plain decimal string at the column's scale, so `19.99` is stored as
 * `"19.99"`, `20` as `"20.00"` and `1.5` at scale 2 as `"1.50"`, with no exponent. A
 * value that is already a string, or a member whose setter or property is typed to
 * refuse a string, such as `float`, is left as it is.
 *
 * @internal
 */
final class DoctrineDecimalStorageCast implements StorageCastInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function forStorage(object $model, string $member, mixed $value, FieldInterface $field): mixed
    {
        return $this->forColumn($model, $member, $value);
    }

    /**
     * The value to write to `$column` of `$entity`: a number as a decimal string when the
     * column is a `decimal` one and its member takes a string, anything else unchanged.
     */
    public function forColumn(object $entity, string $column, mixed $value): mixed
    {
        if (!\is_int($value) && !\is_float($value)) {
            return $value;
        }

        $scale = $this->decimalScale($entity, $column);
        if ($scale === null || !self::acceptsString($entity, $column)) {
            return $value;
        }

        return self::format($value, $scale);
    }

    /**
     * `$value` as a plain decimal string with exactly `$scale` fractional digits. A float
     * is rounded to the scale and written with the fewest digits that read back as the
     * same float, so it keeps what the float holds and invents nothing.
     */
    public static function format(int|float $value, int $scale): string
    {
        if (\is_int($value)) {
            return $scale > 0 ? $value . '.' . \str_repeat('0', $scale) : (string) $value;
        }

        if (!\is_finite($value)) {
            return (string) $value;
        }

        $rounded = \round($value, $scale);
        $exponential = '';
        for ($digits = 1; $digits <= 17; ++$digits) {
            $exponential = \sprintf('%.' . ($digits - 1) . 'e', $rounded);
            if ((float) $exponential === $rounded) {
                break;
            }
        }

        \preg_match('/^(-?)(\d)(?:\.(\d+))?e([+-]\d+)$/', $exponential, $parts);
        $sign = $parts[1] ?? '';
        $mantissa = ($parts[2] ?? '0') . ($parts[3] ?? '');
        $point = 1 + (int) ($parts[4] ?? '0');

        if ($point <= 0) {
            $whole = '0';
            $fraction = \str_repeat('0', -$point) . $mantissa;
        } elseif ($point >= \strlen($mantissa)) {
            $whole = $mantissa . \str_repeat('0', $point - \strlen($mantissa));
            $fraction = '';
        } else {
            $whole = \substr($mantissa, 0, $point);
            $fraction = \substr($mantissa, $point);
        }

        $fraction = \substr(\str_pad($fraction, $scale, '0'), 0, $scale);
        if (\trim($whole . $fraction, '0') === '') {
            $sign = '';
        }

        return $sign . $whole . ($scale > 0 ? '.' . $fraction : '');
    }

    private function decimalScale(object $entity, string $column): ?int
    {
        try {
            $metadata = $this->entityManager->getClassMetadata($entity::class);
        } catch (\Doctrine\ORM\Mapping\MappingException|PersistenceMappingException) {
            // ORM 2 throws its own MappingException for an unmapped class, ORM 3 the persistence one.
            return null;
        }

        if (!$metadata->hasField($column) || $metadata->getTypeOfField($column) !== Types::DECIMAL) {
            return null;
        }

        // ORM 2 returns the mapping as an array, ORM 3 as an object that reads like one.
        $scale = $metadata->getFieldMapping($column)['scale'] ?? null;

        return \is_int($scale) ? $scale : 0;
    }

    /**
     * Whether the member core writes, its setter when it has one and otherwise its
     * property, takes a string.
     */
    private static function acceptsString(object $entity, string $member): bool
    {
        $setter = 'set' . \ucfirst($member);
        if (\method_exists($entity, $setter)) {
            $parameters = (new \ReflectionMethod($entity, $setter))->getParameters();
            $type = isset($parameters[0]) ? $parameters[0]->getType() : null;
        } elseif (\property_exists($entity, $member)) {
            $type = (new \ReflectionProperty($entity, $member))->getType();
        } else {
            return true;
        }

        if ($type === null) {
            return true;
        }

        $names = $type instanceof \ReflectionUnionType
            ? \array_map(static fn(\ReflectionType $member): string => $member instanceof \ReflectionNamedType ? $member->getName() : '', $type->getTypes())
            : [$type instanceof \ReflectionNamedType ? $type->getName() : ''];

        return \in_array('string', $names, true) || \in_array('mixed', $names, true);
    }
}
