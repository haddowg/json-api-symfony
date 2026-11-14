<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

/**
 * A filter compares ids of an entity that several types serve with different id
 * encoders, and none of the types its declaration names serves it.
 *
 * @internal
 */
final class AmbiguousTargetEncoder extends \LogicException
{
    /**
     * @param list<string> $types the types serving the entity
     */
    public function __construct(public readonly string $entityClass, public readonly array $types)
    {
        parent::__construct(\sprintf(
            '%s is served by the types "%s" with different id encoders, and no type the filter names serves it, so its ids cannot be decoded.',
            $entityClass,
            \implode('", "', $types),
        ));
    }
}
