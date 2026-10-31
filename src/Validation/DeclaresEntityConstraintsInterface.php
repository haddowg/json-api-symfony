<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Validation;

/**
 * Declares entity-level constraints that belong to the resource as a whole rather
 * than to one field, such as a {@see Constraint\NativeEntityConstraints} or a
 * {@see Constraint\UniqueEntity} over several fields. Implement it on the resource;
 * they run with the field-attached entity constraints after hydration and after a
 * relationship endpoint changes the resource.
 */
interface DeclaresEntityConstraintsInterface
{
    /**
     * @return list<EntityConstraintInterface>
     */
    public function entityConstraints(): array;
}
