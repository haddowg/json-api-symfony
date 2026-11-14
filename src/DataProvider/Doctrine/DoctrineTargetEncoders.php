<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApiBundle\Server\IdEncoderResolver;

/**
 * The id encoder that decodes a wire id a filter compares with an entity's id, when no
 * relation the filter walks says which type the entity is: an association of a pivot
 * entity, or a foreign key. Every type serving the entity decodes alike when they share
 * an encoder. Otherwise the types the filter's declaration names decide, and when they
 * don't, the filter is ambiguous.
 *
 * @internal
 */
final class DoctrineTargetEncoders
{
    /**
     * @param array<string, class-string> $entityClassByType
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly array $entityClassByType,
        private readonly IdEncoderResolver $idEncoders,
    ) {}

    /**
     * @param class-string $class
     * @param list<string> $named the types the filter's declaration names
     *
     * @throws AmbiguousTargetEncoder when types serving `$class` have different encoders and the named ones don't settle which
     */
    public function encoderFor(string $class, array $named, ?string $server = null): ?IdEncoderInterface
    {
        $name = $this->entityManager->getClassMetadata($class)->getName();

        $serving = [];
        foreach ($this->entityClassByType as $type => $entityClass) {
            if ($this->entityManager->getClassMetadata($entityClass)->getName() === $name) {
                $serving[$type] = $this->idEncoders->encoderFor($type, $server);
            }
        }

        if (self::alike($serving)) {
            return $serving === [] ? null : \reset($serving);
        }

        $namedServing = \array_intersect_key($serving, \array_flip($named));
        if ($namedServing !== [] && self::alike($namedServing)) {
            return \reset($namedServing);
        }

        throw new AmbiguousTargetEncoder($name, \array_keys($serving));
    }

    /**
     * Whether every encoder is the same, or equal: the same class with the same state.
     *
     * @param array<string, ?IdEncoderInterface> $encoders
     */
    private static function alike(array $encoders): bool
    {
        $first = \reset($encoders);
        foreach ($encoders as $encoder) {
            if ($encoder != $first) {
                return false;
            }
        }

        return true;
    }
}
