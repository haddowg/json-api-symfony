<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Validation;

use Egulias\EmailValidator\EmailValidator;
use haddowg\JsonApi\Resource\Constraint\Custom;
use Symfony\Component\Validator\Constraints\Email;

/**
 * Translates core's one shipped {@see Custom} constraint, `email.strict`
 * (emitted by `Email::strict()`), into a strict-mode Symfony {@see Email} — RFC
 * 5322 parsing rather than the lenient HTML5 pattern.
 *
 * Symfony's strict mode requires `egulias/email-validator`; when it is not
 * installed this degrades to HTML5 mode rather than failing the request, so the
 * dependency stays a `suggest` rather than a hard requirement.
 */
final class StrictEmailConstraintTranslator implements CustomConstraintTranslatorInterface
{
    public const string ID = 'email.strict';

    public function supports(string $id): bool
    {
        return $id === self::ID;
    }

    public function translate(Custom $constraint): array
    {
        $mode = \class_exists(EmailValidator::class)
            ? Email::VALIDATION_MODE_STRICT
            : Email::VALIDATION_MODE_HTML5;

        return [new Email(mode: $mode)];
    }
}
