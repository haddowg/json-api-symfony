<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Validation;

use haddowg\JsonApi\Resource\Constraint\Custom;
use Symfony\Component\Validator\Constraint;

/**
 * Translates a core {@see Custom} constraint — the opaque, `$id`-keyed escape
 * hatch core ships for adapter-specific rules — into Symfony constraints. The
 * bridge resolves the first translator whose {@see supports()} matches the
 * custom's `$id`; an application registers its own by tagging a service with
 * {@see \haddowg\JsonApiBundle\JsonApiBundle::CUSTOM_CONSTRAINT_TRANSLATOR_TAG}
 * (autoconfigured from this interface).
 *
 * Core ships exactly one `Custom` today (`email.strict`, from `Email::strict()`),
 * which {@see StrictEmailConstraintTranslator} handles.
 */
interface CustomConstraintTranslatorInterface
{
    /**
     * Whether this translator handles the given custom constraint `$id`.
     */
    public function supports(string $id): bool;

    /**
     * The Symfony constraints the custom translates to.
     *
     * @return list<Constraint>
     */
    public function translate(Custom $constraint): array;
}
