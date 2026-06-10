<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Validation;

use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;

/**
 * Marks a constraint that is validated against the **hydrated entity**
 * (post-hydration, pre-commit) rather than the request document — for rules that
 * need the persisted object or the database, such as uniqueness.
 *
 * The bridge skips these in the document-first {@see ResourceValidator::validate()}
 * pass and runs them in {@see ResourceValidator::validateEntity()}, which the
 * write handler calls once the hydrator has built the entity. A constraint
 * implementing this interface translates (via the {@see ConstraintTranslator},
 * including any registered {@see ConstraintTranslatorInterface}) to a Symfony
 * **class** constraint validated against the entity object — so an application's
 * own entity-level rule hooks into the same seam by implementing this interface
 * and registering a translator for it.
 */
interface EntityConstraintInterface extends ConstraintInterface {}
