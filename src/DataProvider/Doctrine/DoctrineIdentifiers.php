<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Resource\Field\IdEncoderInterface;

/**
 * Converts and binds single-field entity identifiers through their mapped Doctrine
 * type, so an id that is not a plain int or string works on every read and write
 * path. A Doctrine `uuid` id, for example, is a `Uuid` object in PHP, a 16-byte
 * string in the database on a platform without a native UUID type, and its RFC 4122
 * string on the wire.
 *
 * An id value can arrive in any of those forms: read off an entity, projected by a
 * query, or decoded from the wire. {@see key()} reduces each form to the same string,
 * {@see bind()} and {@see bindList()} bind the database form with the type's binding
 * type, and {@see toPhp()} gives the form an entity holds, which
 * `EntityManager::getReference()` needs.
 *
 * An identifier with no mapped field type, such as an id that is itself an
 * association, is passed through unconverted, as before.
 *
 * @internal
 */
final class DoctrineIdentifiers
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    /**
     * The database form of `$value`, or null when the type cannot convert it.
     *
     * @param ClassMetadata<object> $metadata
     */
    public function toDatabase(ClassMetadata $metadata, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = $this->type($metadata);
        if ($type === null) {
            return \is_scalar($value) ? $value : null;
        }

        try {
            return $type->convertToDatabaseValue($value, $this->entityManager->getConnection()->getDatabasePlatform());
        } catch (ConversionException|\InvalidArgumentException|\TypeError|\ValueError) {
            return null;
        }
    }

    /**
     * The PHP form of `$value`, as a hydrated entity holds it, or null when the type
     * cannot convert it or the conversion does not round-trip (a non-numeric string
     * against an integer id).
     *
     * @param ClassMetadata<object> $metadata
     */
    public function toPhp(ClassMetadata $metadata, mixed $value): mixed
    {
        $type = $this->type($metadata);
        if ($type === null) {
            return \is_scalar($value) ? $value : null;
        }

        $database = $this->toDatabase($metadata, $value);
        if ($database === null) {
            return null;
        }

        try {
            $php = $type->convertToPHPValue($database, $this->entityManager->getConnection()->getDatabasePlatform());
        } catch (ConversionException|\InvalidArgumentException|\TypeError|\ValueError) {
            return null;
        }

        return $this->key($metadata, $php) === self::stringOf($database) ? $php : null;
    }

    /**
     * A string that is the same for every form of one id, for keying rows by id.
     * Null when the value is not an id of this type.
     *
     * @param ClassMetadata<object> $metadata
     */
    public function key(ClassMetadata $metadata, mixed $value): ?string
    {
        return self::stringOf($this->toDatabase($metadata, $value));
    }

    /**
     * The wire id of `$value`: the encoder's encoding of its PHP form, or that form
     * as a string when the type has no encoder. A `uuid` id with no encoder is its
     * canonical RFC 4122 string. Null when the value is not an id of this type.
     *
     * @param ClassMetadata<object> $metadata
     */
    public function wireId(ClassMetadata $metadata, mixed $value, ?IdEncoderInterface $encoder): ?string
    {
        if ($value === null) {
            return null;
        }

        $php = $this->toPhp($metadata, $value);
        if ($php === null) {
            return null;
        }

        if ($encoder !== null) {
            return $encoder->encode($php);
        }

        return \is_scalar($php) || $php instanceof \Stringable ? (string) $php : null;
    }

    /**
     * The identifier value of a managed `$entity`, or null when it has none or its
     * identifier is composite.
     *
     * @param ClassMetadata<object> $metadata
     */
    public function valueOf(ClassMetadata $metadata, object $entity): mixed
    {
        if ($metadata->isIdentifierComposite) {
            return null;
        }

        return $metadata->getIdentifierValues($entity)[$metadata->getSingleIdentifierFieldName()] ?? null;
    }

    /**
     * Binds one id to `:$name` in its database form, with the type's binding type.
     *
     * @param ClassMetadata<object> $metadata
     */
    public function bind(QueryBuilder $builder, string $name, ClassMetadata $metadata, mixed $value): QueryBuilder
    {
        $type = $this->type($metadata);
        if ($type === null) {
            return $builder->setParameter($name, $value);
        }

        return $builder->setParameter($name, $this->toDatabase($metadata, $value), $type->getBindingType());
    }

    /**
     * Binds a list of ids to `:$name` for an `IN (...)`, each in its database form,
     * with the array form of the type's binding type. Values the type cannot convert
     * are dropped.
     *
     * @param ClassMetadata<object> $metadata
     * @param iterable<mixed>       $values
     */
    public function bindList(QueryBuilder $builder, string $name, ClassMetadata $metadata, iterable $values): QueryBuilder
    {
        $type = $this->type($metadata);
        if ($type === null) {
            return $builder->setParameter($name, \is_array($values) ? \array_values($values) : \iterator_to_array($values, false));
        }

        $converted = [];
        foreach ($values as $value) {
            $database = $this->toDatabase($metadata, $value);
            if ($database !== null) {
                $converted[] = $database;
            }
        }

        return $builder->setParameter($name, $converted, self::listType($type));
    }

    /**
     * The Doctrine type of the single identifier field, or null when there is none
     * (a composite id, or an id that is an association).
     *
     * @param ClassMetadata<object> $metadata
     */
    private function type(ClassMetadata $metadata): ?Type
    {
        if ($metadata->isIdentifierComposite) {
            return null;
        }

        $field = $metadata->getSingleIdentifierFieldName();
        if (!$metadata->hasField($field)) {
            return null;
        }

        $type = $metadata->getTypeOfField($field);

        return $type !== null && Type::hasType($type) ? Type::getType($type) : null;
    }

    /**
     * The array parameter type matching a type's binding type. DBAL 4 has enum cases
     * and DBAL 3 int constants under the same names, so the return is left untyped.
     *
     * @return ArrayParameterType
     */
    private static function listType(Type $type): mixed
    {
        return match ($type->getBindingType()) {
            ParameterType::INTEGER => ArrayParameterType::INTEGER,
            ParameterType::BINARY, ParameterType::LARGE_OBJECT => ArrayParameterType::BINARY,
            ParameterType::ASCII => ArrayParameterType::ASCII,
            default => ArrayParameterType::STRING,
        };
    }

    private static function stringOf(mixed $value): ?string
    {
        return \is_scalar($value) || $value instanceof \Stringable ? (string) $value : null;
    }
}
