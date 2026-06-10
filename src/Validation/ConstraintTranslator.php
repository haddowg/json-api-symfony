<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Validation;

use Egulias\EmailValidator\EmailValidator;
use haddowg\JsonApi\Resource\Constraint\After;
use haddowg\JsonApi\Resource\Constraint\Before;
use haddowg\JsonApi\Resource\Constraint\Between;
use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Constraint\Each;
use haddowg\JsonApi\Resource\Constraint\EmailFormat;
use haddowg\JsonApi\Resource\Constraint\ExclusiveMax;
use haddowg\JsonApi\Resource\Constraint\ExclusiveMin;
use haddowg\JsonApi\Resource\Constraint\In;
use haddowg\JsonApi\Resource\Constraint\IpFormat;
use haddowg\JsonApi\Resource\Constraint\Max;
use haddowg\JsonApi\Resource\Constraint\MaxItems;
use haddowg\JsonApi\Resource\Constraint\MaxLength;
use haddowg\JsonApi\Resource\Constraint\MaxProperties;
use haddowg\JsonApi\Resource\Constraint\Min;
use haddowg\JsonApi\Resource\Constraint\MinItems;
use haddowg\JsonApi\Resource\Constraint\MinLength;
use haddowg\JsonApi\Resource\Constraint\MinProperties;
use haddowg\JsonApi\Resource\Constraint\MultipleOf;
use haddowg\JsonApi\Resource\Constraint\NotIn;
use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Constraint\SlugFormat;
use haddowg\JsonApi\Resource\Constraint\UniqueItems;
use haddowg\JsonApi\Resource\Constraint\UrlFormat;
use haddowg\JsonApi\Resource\Constraint\UuidFormat;
use haddowg\JsonApi\Resource\Constraint\When;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\DivisibleBy;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\Ip;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\LessThan;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Unique;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Constraints\Uuid;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Translates a core {@see ConstraintInterface} value object into the Symfony
 * {@see Constraint}(s) that enforce it. Core declares constraints as metadata and
 * never executes them; this is the adapter that gives the vocabulary teeth under
 * Symfony's validator.
 *
 * Presence and nullability ({@see \haddowg\JsonApi\Resource\Constraint\Required} /
 * {@see \haddowg\JsonApi\Resource\Constraint\Nullable}) are *not* translated here —
 * they are resolved by the {@see ResourceValidator} against the create/update
 * context, since Symfony expresses them as a field's `Required`/`Optional` wrapper
 * plus `NotBlank`/`NotNull`, not as standalone value constraints.
 *
 * The closure-carrying constraints — {@see When} (a condition closure) and
 * {@see After}/{@see Before}/{@see Between} (bounds that may be a closure evaluated
 * at validation time) — have no stock Symfony equivalent that accepts a PHP
 * closure, so each translates to a {@see Callback}: `When` evaluates its condition
 * and, when true, validates the value against the translated inner constraints; the
 * date bounds coerce the value to a `\DateTimeImmutable` and compare it against the
 * resolved bound.
 *
 * A constraint outside this built-in vocabulary is delegated to the registered
 * {@see ConstraintTranslatorInterface}s (first {@see ConstraintTranslatorInterface::supports()}
 * match wins) — the typed extension point applications use for their own
 * constraint value objects; if none matches, translation fails loud.
 */
final class ConstraintTranslator
{
    /**
     * @var list<ConstraintTranslatorInterface>
     */
    private readonly array $extensionTranslators;

    /**
     * @param iterable<ConstraintTranslatorInterface> $extensionTranslators in priority order
     */
    public function __construct(iterable $extensionTranslators = [])
    {
        $this->extensionTranslators = \is_array($extensionTranslators)
            ? \array_values($extensionTranslators)
            : \iterator_to_array($extensionTranslators, false);
    }

    /**
     * The Symfony constraints enforcing one core constraint.
     *
     * @return list<Constraint>
     *
     * @throws \LogicException for a constraint the bridge does not yet translate
     */
    public function translate(ConstraintInterface $constraint): array
    {
        return match (true) {
            $constraint instanceof In => [new Choice(choices: $this->scalarList($constraint->values))],
            $constraint instanceof NotIn => [new Choice(choices: $this->scalarList($constraint->values), match: false)],
            $constraint instanceof Min => [new GreaterThanOrEqual(value: $constraint->value)],
            $constraint instanceof Max => [new LessThanOrEqual(value: $constraint->value)],
            $constraint instanceof ExclusiveMin => [new GreaterThan(value: $constraint->value)],
            $constraint instanceof ExclusiveMax => [new LessThan(value: $constraint->value)],
            $constraint instanceof MultipleOf => [new DivisibleBy(value: $constraint->value)],
            $constraint instanceof MinLength => [new Length(min: \max(0, $constraint->value))],
            $constraint instanceof MaxLength => [new Length(max: \max(1, $constraint->value))],
            $constraint instanceof MinItems => [new Count(min: \max(0, $constraint->value))],
            $constraint instanceof MaxItems => [new Count(max: \max(0, $constraint->value))],
            $constraint instanceof MinProperties => [new Count(min: \max(0, $constraint->value))],
            $constraint instanceof MaxProperties => [new Count(max: \max(0, $constraint->value))],
            $constraint instanceof UniqueItems => [new Unique()],
            $constraint instanceof EmailFormat => [$this->email($constraint)],
            $constraint instanceof UrlFormat => [$this->url($constraint)],
            $constraint instanceof UuidFormat => [$this->uuid($constraint)],
            $constraint instanceof IpFormat => [new Ip(version: match ($constraint->version) {
                4 => Ip::V4,
                6 => Ip::V6,
                default => Ip::ALL,
            })],
            $constraint instanceof Pattern => [new Regex(pattern: $this->delimit($constraint->regex))],
            $constraint instanceof SlugFormat => [new Regex(pattern: $this->delimit($constraint->regex))],
            $constraint instanceof Each => [new All(constraints: $this->translateAll($constraint->constraints))],
            $constraint instanceof When => [$this->conditional($constraint)],
            $constraint instanceof After => [$this->dateBound($constraint->bound, true, 'This value should be after {{ limit }}.')],
            $constraint instanceof Before => [$this->dateBound($constraint->bound, false, 'This value should be before {{ limit }}.')],
            $constraint instanceof Between => [$this->dateRange($constraint->min, $constraint->max)],
            default => $this->translateExtension($constraint),
        };
    }

    /**
     * Translates a list of constraints, flattening the per-constraint results
     * (used for the inner constraints of {@see Each}).
     *
     * @param list<ConstraintInterface> $constraints
     *
     * @return list<Constraint>
     */
    private function translateAll(array $constraints): array
    {
        $translated = [];
        foreach ($constraints as $constraint) {
            foreach ($this->translate($constraint) as $symfonyConstraint) {
                $translated[] = $symfonyConstraint;
            }
        }

        return $translated;
    }

    /**
     * Translates {@see When} to a {@see Callback} that evaluates the condition
     * closure against the value and, only when it returns true, validates the value
     * against the translated inner constraints. Core's condition is an arbitrary PHP
     * closure, which Symfony's own `When` (an ExpressionLanguage string) cannot
     * express — so the conditional logic runs in the callback.
     */
    private function conditional(When $constraint): Callback
    {
        $condition = $constraint->condition;
        $inner = $this->translateAll($constraint->constraints);

        return new Callback(callback: static function (mixed $value, ExecutionContextInterface $context) use ($condition, $inner): void {
            if ($condition($value) === true) {
                $context->getValidator()->inContext($context)->validate($value, $inner);
            }
        });
    }

    /**
     * A {@see Callback} enforcing a single date bound ({@see After}/{@see Before}).
     * The value is coerced to a `\DateTimeImmutable`; an absent, empty or unparseable
     * value is left to other layers (presence rules, the hydrator) and skipped. The
     * bound is resolved at validation time, so a closure bound such as "now" reflects
     * the moment of the request.
     *
     * @param \DateTimeInterface|\Closure(): \DateTimeInterface $bound
     * @param bool $after true for a lower bound (`After`), false for an upper bound (`Before`)
     */
    private function dateBound(\DateTimeInterface|\Closure $bound, bool $after, string $message): Callback
    {
        return new Callback(callback: static function (mixed $value, ExecutionContextInterface $context) use ($bound, $after, $message): void {
            $date = self::toDateTime($value);
            if ($date === null) {
                return;
            }

            $limit = self::resolveBound($bound);
            if ($after ? $date <= $limit : $date >= $limit) {
                $context->buildViolation($message)
                    ->setParameter('{{ limit }}', $limit->format(\DateTimeInterface::ATOM))
                    ->addViolation();
            }
        });
    }

    /**
     * A {@see Callback} enforcing an inclusive `[min, max]` date range ({@see Between}),
     * with the same value coercion and validation-time bound resolution as
     * {@see dateBound()}.
     *
     * @param \DateTimeInterface|\Closure(): \DateTimeInterface $min
     * @param \DateTimeInterface|\Closure(): \DateTimeInterface $max
     */
    private function dateRange(\DateTimeInterface|\Closure $min, \DateTimeInterface|\Closure $max): Callback
    {
        return new Callback(callback: static function (mixed $value, ExecutionContextInterface $context) use ($min, $max): void {
            $date = self::toDateTime($value);
            if ($date === null) {
                return;
            }

            $lower = self::resolveBound($min);
            $upper = self::resolveBound($max);
            if ($date < $lower || $date > $upper) {
                $context->buildViolation('This value should be between {{ min }} and {{ max }}.')
                    ->setParameter('{{ min }}', $lower->format(\DateTimeInterface::ATOM))
                    ->setParameter('{{ max }}', $upper->format(\DateTimeInterface::ATOM))
                    ->addViolation();
            }
        });
    }

    /**
     * Coerces a raw attribute value to a comparable instant, or null when it is not
     * a non-empty date string. Presence and format are other layers' concern, so a
     * bound check simply does not apply to a value it cannot read as a date.
     */
    private static function toDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Resolves a fixed or closure bound to a `\DateTimeImmutable`, evaluating a
     * closure now so a deferred bound reflects validation time.
     *
     * @param \DateTimeInterface|\Closure(): \DateTimeInterface $bound
     */
    private static function resolveBound(\DateTimeInterface|\Closure $bound): \DateTimeImmutable
    {
        $resolved = $bound instanceof \Closure ? $bound() : $bound;
        if (!$resolved instanceof \DateTimeInterface) {
            throw new \LogicException('A date constraint bound closure must return a \DateTimeInterface.');
        }

        return \DateTimeImmutable::createFromInterface($resolved);
    }

    /**
     * Translates {@see EmailFormat}, reading its typed `strict` flag. Strict mode
     * needs `egulias/email-validator`; without it strict degrades to HTML5 rather
     * than failing the request, keeping that package a `suggest`.
     */
    private function email(EmailFormat $constraint): Email
    {
        if (!$constraint->strict) {
            return new Email();
        }

        return new Email(mode: \class_exists(EmailValidator::class)
            ? Email::VALIDATION_MODE_STRICT
            : Email::VALIDATION_MODE_HTML5);
    }

    /**
     * Delegates a constraint outside the built-in vocabulary to the first
     * registered {@see ConstraintTranslatorInterface} that supports it.
     *
     * @return list<Constraint>
     */
    private function translateExtension(ConstraintInterface $constraint): array
    {
        foreach ($this->extensionTranslators as $translator) {
            if ($translator->supports($constraint)) {
                return $translator->translate($constraint);
            }
        }

        throw new \LogicException(\sprintf(
            'No translator is registered for the JSON:API constraint %s. Implement a %s and tag it, or use a built-in constraint.',
            $constraint::class,
            ConstraintTranslatorInterface::class,
        ));
    }

    private function url(UrlFormat $constraint): Url
    {
        $protocols = $this->urlProtocols($constraint);

        // `requireTld` was added in symfony/validator 7.1 (and its default becomes
        // `true` in 8.0): adopt the future default where the option exists, and
        // omit it on 6.4 — passing an unknown named argument there would error.
        if (\property_exists(Url::class, 'requireTld')) {
            return new Url(protocols: $protocols, requireTld: true);
        }

        return new Url(protocols: $protocols);
    }

    /**
     * @return list<string>
     */
    private function urlProtocols(UrlFormat $constraint): array
    {
        $schemes = $this->scalarList($constraint->allowedSchemes);

        return $schemes === [] ? ['http', 'https'] : \array_map('\strval', $schemes);
    }

    private function uuid(UuidFormat $constraint): Uuid
    {
        $version = $constraint->version;
        if ($version === null || $version < 1 || $version > 8) {
            return new Uuid();
        }

        return new Uuid(versions: [$version]);
    }

    /**
     * Wraps a core regex (an ECMA-262 source with no delimiters) as a PCRE
     * pattern, escaping the delimiter where it appears literally.
     */
    private function delimit(string $regex): string
    {
        return '~' . \str_replace('~', '\\~', $regex) . '~';
    }

    /**
     * @param array<int, mixed> $values
     *
     * @return list<mixed>
     */
    private function scalarList(array $values): array
    {
        return \array_values($values);
    }
}
