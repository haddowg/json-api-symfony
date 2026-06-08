<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Validation;

use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Constraint\Custom;
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
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\All;
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
 * The `$id`-keyed {@see Custom} escape hatch is delegated to the registered
 * {@see CustomConstraintTranslatorInterface}s. The closure-based
 * {@see \haddowg\JsonApi\Resource\Constraint\When} and the date/timezone value
 * constraints (`After`/`Before`/`Between`/`Timezone`) are not yet translated — a
 * resource using one raises a clear error rather than silently skipping it; they
 * land in a follow-up (see ADR 0012).
 */
final class ConstraintTranslator
{
    /**
     * @var list<CustomConstraintTranslatorInterface>
     */
    private readonly array $customTranslators;

    /**
     * @param iterable<CustomConstraintTranslatorInterface> $customTranslators in priority order
     */
    public function __construct(iterable $customTranslators = [])
    {
        $this->customTranslators = \is_array($customTranslators)
            ? \array_values($customTranslators)
            : \iterator_to_array($customTranslators, false);
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
            $constraint instanceof EmailFormat => [new Email()],
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
            $constraint instanceof Custom => $this->translateCustom($constraint),
            default => throw new \LogicException(\sprintf(
                'The JSON:API constraint %s is not yet translated by the Symfony Validator bridge.',
                $constraint::class,
            )),
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
     * @return list<Constraint>
     */
    private function translateCustom(Custom $constraint): array
    {
        foreach ($this->customTranslators as $translator) {
            if ($translator->supports($constraint->id)) {
                return $translator->translate($constraint);
            }
        }

        throw new \LogicException(\sprintf(
            'No CustomConstraintTranslator is registered for the custom JSON:API constraint id "%s".',
            $constraint->id,
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
