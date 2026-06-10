<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Validation;

use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Translates a custom {@see ConstraintInterface} — one outside core's built-in
 * vocabulary — into the Symfony {@see Constraint}(s) that enforce it. An
 * application defines its own typed constraint value object (attaching it to a
 * field with `constrain()`) and registers a translator for it by tagging a
 * service with {@see \haddowg\JsonApiBundle\JsonApiBundle::CONSTRAINT_TRANSLATOR_TAG}
 * (autoconfigured from this interface).
 *
 * The {@see ConstraintTranslator} consults the registered translators — first
 * {@see supports()} match wins — for any constraint it does not translate itself,
 * and raises a clear error if none matches. This is the typed replacement for the
 * removed `Custom` escape hatch: a translator matches on the constraint's class,
 * not a string id.
 */
interface ConstraintTranslatorInterface
{
    /**
     * Whether this translator handles the given constraint.
     */
    public function supports(ConstraintInterface $constraint): bool;

    /**
     * The Symfony constraints the given constraint translates to.
     *
     * @return list<Constraint>
     */
    public function translate(ConstraintInterface $constraint): array;
}
