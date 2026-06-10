<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Validation\Constraint;

use haddowg\JsonApi\Resource\Constraint\Context;
use haddowg\JsonApiBundle\Validation\EntityConstraintInterface;

/**
 * Asserts that the value(s) of one or more fields are unique across the resource's
 * Doctrine entity. An {@see EntityConstraintInterface}: it is validated against the
 * hydrated entity, not the request document, so the bridge runs it post-hydration
 * and translates it to Symfony's doctrine-bridge `UniqueEntity` — which queries the
 * repository and, on update, excludes the current record by its identifier. The
 * entity class is inferred from the entity under validation, so only the field(s)
 * need declaring: `->constrain(new UniqueEntity(['email']))`.
 */
final readonly class UniqueEntity implements EntityConstraintInterface
{
    /**
     * @var list<string>
     */
    public array $fields;

    /**
     * @param list<string> $fields the field(s) that together must be unique
     */
    public function __construct(
        array $fields,
        public ?string $message = null,
        public Context $context = new Context(),
    ) {
        $this->fields = $fields;
    }

    public function context(): Context
    {
        return $this->context;
    }
}
