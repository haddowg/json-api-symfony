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
 *
 * The other options pass through to Symfony's constraint. `repositoryMethod` names the
 * repository method that finds the clashing rows, so "unique among the visible rows"
 * is a method that filters them; `entityClass` checks against another class, such as
 * the root of an inheritance tree; `errorPath` picks the field the 422 points at; and
 * `ignoreNull` skips the check when the field, or the named fields, are null. Left
 * null, each keeps Symfony's default.
 */
final readonly class UniqueEntity implements EntityConstraintInterface
{
    /**
     * @var list<string>
     */
    public array $fields;

    /**
     * @var bool|string|list<string>|null
     */
    public bool|string|array|null $ignoreNull;

    /**
     * @param list<string>              $fields     the field(s) that together must be unique
     * @param bool|string|list<string>|null $ignoreNull whether, or for which fields, a null value skips the check
     */
    public function __construct(
        array $fields,
        public ?string $message = null,
        public Context $context = new Context(),
        public ?string $repositoryMethod = null,
        public ?string $errorPath = null,
        bool|string|array|null $ignoreNull = null,
        public ?string $entityClass = null,
    ) {
        $this->fields = $fields;
        $this->ignoreNull = $ignoreNull;
    }

    public function context(): Context
    {
        return $this->context;
    }
}
