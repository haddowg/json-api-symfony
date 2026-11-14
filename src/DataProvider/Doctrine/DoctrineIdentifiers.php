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
 * An id that is itself an association, a OneToOne mapped with `#[ORM\Id]`, is the
 * target entity's id: an entity or proxy value reduces to that id, recursively, and
 * converts and binds through the target id's type. DQL addresses such an id as
 * `IDENTITY(alias.field)`, which {@see path()} gives.
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
        $value = $this->reduce($metadata, $value);
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
        $value = $this->reduce($metadata, $value);
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
     * identifier is composite. An association id gives the target's id.
     *
     * @param ClassMetadata<object> $metadata
     */
    public function valueOf(ClassMetadata $metadata, object $entity): mixed
    {
        if ($metadata->isIdentifierComposite) {
            return null;
        }

        return $this->reduce($metadata, $metadata->getIdentifierValues($entity)[$metadata->getSingleIdentifierFieldName()] ?? null);
    }

    /**
     * The value of `$field` on `$entity`, with an association id reduced to the target's
     * id, as a cursor keyset reads it.
     *
     * @param ClassMetadata<object> $metadata
     */
    public function fieldValue(ClassMetadata $metadata, object $entity, string $field): mixed
    {
        if (!$metadata->isIdentifierComposite && $metadata->hasAssociation($field) && $metadata->getSingleIdentifierFieldName() === $field) {
            return $this->valueOf($metadata, $entity);
        }

        return $metadata->getFieldValue($entity, $field);
    }

    /**
     * The DQL for `$alias`'s single id: `IDENTITY(alias.field)` when the id is an
     * association, since DQL rejects the bare association path in a SELECT, and
     * `alias.field` otherwise. GROUP BY takes the bare path either way.
     *
     * @param ClassMetadata<object> $metadata
     */
    public static function path(ClassMetadata $metadata, string $alias): string
    {
        $field = $metadata->getSingleIdentifierFieldName();
        $path = $alias . '.' . $field;

        return $metadata->hasAssociation($field) ? 'IDENTITY(' . $path . ')' : $path;
    }

    /**
     * The DQL that stands for `$alias`'s entity where DQL reads its id, as a COUNT or an
     * entity comparison does: the alias itself, or the id's `IDENTITY()` when the id is
     * an association, which DQL cannot read through the bare alias.
     *
     * @param ClassMetadata<object> $metadata
     */
    public static function entity(ClassMetadata $metadata, string $alias): string
    {
        if ($metadata->isIdentifierComposite || !$metadata->hasAssociation($metadata->getSingleIdentifierFieldName())) {
            return $alias;
        }

        return self::path($metadata, $alias);
    }

    /**
     * The DQL that matches the single-valued association at `$association`
     * (`pivot.track`) to the entity `$alias` names: `pivot.track = alias`, or
     * `IDENTITY(pivot.track) = IDENTITY(alias.field)` when that entity's id is an
     * association, since DQL compares an association only to an alias or a parameter.
     *
     * @param ClassMetadata<object> $metadata the metadata of the entity `$alias` names
     */
    public static function joins(string $association, ClassMetadata $metadata, string $alias): string
    {
        $entity = self::entity($metadata, $alias);

        return $entity === $alias
            ? $association . ' = ' . $alias
            : 'IDENTITY(' . $association . ') = ' . $entity;
    }

    /**
     * Reduces an entity or proxy held by an association id to the target's own id,
     * through as many association ids as the chain has. The UnitOfWork's copy is read
     * first, so a proxy is never initialised.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function reduce(ClassMetadata $metadata, mixed $value): mixed
    {
        while (\is_object($value) && !$metadata->isIdentifierComposite) {
            $field = $metadata->getSingleIdentifierFieldName();
            if (!$metadata->hasAssociation($field)) {
                break;
            }

            $metadata = $this->entityManager->getClassMetadata($metadata->getAssociationTargetClass($field));
            if ($metadata->isIdentifierComposite) {
                return null;
            }

            $unitOfWork = $this->entityManager->getUnitOfWork();
            $identifier = $unitOfWork->isInIdentityMap($value)
                ? $unitOfWork->getEntityIdentifier($value)
                : $metadata->getIdentifierValues($value);
            $value = $identifier[$metadata->getSingleIdentifierFieldName()] ?? null;
        }

        return $value;
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
     * The Doctrine type of the single identifier field, or of the target's id when
     * that field is an association. Null for a composite id.
     *
     * @param ClassMetadata<object> $metadata
     */
    public function type(ClassMetadata $metadata): ?Type
    {
        if ($metadata->isIdentifierComposite) {
            return null;
        }

        $field = $metadata->getSingleIdentifierFieldName();
        if ($metadata->hasAssociation($field)) {
            return $this->type($this->entityManager->getClassMetadata($metadata->getAssociationTargetClass($field)));
        }

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
    public static function listType(Type $type): mixed
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
