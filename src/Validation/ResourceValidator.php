<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Validation;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\CompareField;
use haddowg\JsonApi\Resource\Constraint\Comparison;
use haddowg\JsonApi\Resource\Constraint\Nullable;
use haddowg\JsonApi\Resource\Constraint\Required;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Optional;
use Symfony\Component\Validator\Constraints\Required as RequiredField;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Runs a resource's declared constraints against an incoming create/update
 * document and raises a {@see ValidationFailed} (`422`) carrying one pointer-bearing
 * {@see Error} per violation. This is the bridge core never had: it stores
 * constraints as metadata but never executes them.
 *
 * Validation is **document-first**: the resource's `attributes` are validated as
 * the request sends them (so a violation maps cleanly to
 * `/data/attributes/<name>`), before hydration touches the entity. Each attribute
 * field's constraints are filtered by the create/update {@see \haddowg\JsonApi\Resource\Constraint\Context}
 * and translated by the {@see ConstraintTranslator}, then wrapped per the
 * {@see Required}/{@see Nullable} resolution into a Symfony {@see Collection}:
 *  - a create-required field is a {@see RequiredField} with `NotBlank` (present and
 *    non-empty); on update it relaxes to {@see Optional} with `NotBlank` (a partial
 *    update may omit it, but a supplied value must be non-empty);
 *  - a non-nullable field gets `NotNull`, so an explicit `null` is rejected when
 *    the field is present;
 *  - unknown attributes are ignored (the hydrator ignores them too).
 *
 * The `Id` field and relationships are not attributes, so they are skipped
 * (relationship validation arrives with the relationship phase).
 *
 * {@see \haddowg\JsonApi\Resource\Constraint\CompareField} (a cross-field rule) is
 * the exception to per-field validation: it is evaluated at the **document** level,
 * after the {@see Collection} pass, because the comparison needs the sibling field's
 * value — which the `Collection` validates in isolation.
 */
final class ResourceValidator
{
    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly ConstraintTranslator $translator,
        private readonly JsonPointerBuilder $pointers,
    ) {}

    /**
     * @throws ValidationFailed when the document violates the resource's constraints
     */
    public function validate(AbstractResource $resource, JsonApiRequestInterface $request, bool $creating): void
    {
        $data = $request->getResource();
        if (!\is_array($data)) {
            return; // a missing/malformed data member is core's concern, raised in hydration
        }

        $attributes = $data['attributes'] ?? [];
        if (!\is_array($attributes)) {
            return;
        }

        $fields = [];
        $compares = [];
        foreach ($resource->fields() as $field) {
            if ($field instanceof Id || $field instanceof RelationInterface) {
                continue;
            }
            if ($field->isReadOnly($creating)) {
                continue;
            }

            $fields[$field->name()] = $this->fieldConstraint($field, $creating);
            foreach ($this->compareConstraints($field, $creating) as $compare) {
                $compares[] = [$field->name(), $compare];
            }
        }

        if ($fields === []) {
            return;
        }

        $errors = [];

        $violations = $this->validator->validate($attributes, new Collection(fields: $fields, allowExtraFields: true));
        foreach ($violations as $violation) {
            $errors[] = new Error(
                status: '422',
                code: 'VALIDATION_FAILED',
                title: 'Unprocessable Entity',
                detail: (string) $violation->getMessage(),
                source: ErrorSource::fromPointer($this->pointers->forAttribute((string) $violation->getPropertyPath())),
            );
        }

        // Cross-field rules are validated at the document level, where the sibling
        // value is in scope (the per-field Collection sees each value in isolation).
        foreach ($compares as [$owner, $compare]) {
            $error = $this->compareError($owner, $compare, $attributes);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        if ($errors === []) {
            return;
        }

        throw new ValidationFailed($errors);
    }

    /**
     * @param array<mixed, mixed> $attributes
     */
    private function compareError(string $owner, CompareField $compare, array $attributes): ?Error
    {
        // A comparison needs both values present and non-null; presence is the
        // Required rule's concern, not this one's.
        if (!\array_key_exists($owner, $attributes) || !\array_key_exists($compare->field, $attributes)) {
            return null;
        }

        $value = $attributes[$owner];
        $other = $attributes[$compare->field];
        if ($value === null || $other === null || $this->satisfies($compare->operator, $value, $other)) {
            return null;
        }

        return new Error(
            status: '422',
            code: 'VALIDATION_FAILED',
            title: 'Unprocessable Entity',
            detail: \sprintf('This value should be %s the value of "%s".', $this->describe($compare->operator), $compare->field),
            source: ErrorSource::fromPointer($this->pointers->forAttribute('[' . $owner . ']')),
        );
    }

    private function satisfies(Comparison $operator, mixed $value, mixed $other): bool
    {
        [$left, $right] = $this->comparable($value, $other);
        $order = $left <=> $right;

        return match ($operator) {
            Comparison::EqualTo => $order === 0,
            Comparison::NotEqualTo => $order !== 0,
            Comparison::GreaterThan => $order > 0,
            Comparison::GreaterThanOrEqual => $order >= 0,
            Comparison::LessThan => $order < 0,
            Comparison::LessThanOrEqual => $order <= 0,
        };
    }

    /**
     * Coerces a pair of raw attribute values to a comparable pair: two numbers, two
     * dates, or — failing both — the raw values for a string/loose comparison. A
     * spaceship over the result compares by value (dates chronologically).
     *
     * @return array{mixed, mixed}
     */
    private function comparable(mixed $value, mixed $other): array
    {
        if (\is_numeric($value) && \is_numeric($other)) {
            return [(float) $value, (float) $other];
        }

        $date = $this->asDate($value);
        $otherDate = $this->asDate($other);
        if ($date !== null && $otherDate !== null) {
            return [$date, $otherDate];
        }

        return [$value, $other];
    }

    private function asDate(mixed $value): ?\DateTimeImmutable
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

    private function describe(Comparison $operator): string
    {
        return match ($operator) {
            Comparison::EqualTo => 'equal to',
            Comparison::NotEqualTo => 'not equal to',
            Comparison::GreaterThan => 'greater than',
            Comparison::GreaterThanOrEqual => 'greater than or equal to',
            Comparison::LessThan => 'less than',
            Comparison::LessThanOrEqual => 'less than or equal to',
        };
    }

    /**
     * The cross-field comparison constraints declared on a field, filtered by context.
     *
     * @return list<CompareField>
     */
    private function compareConstraints(FieldInterface $field, bool $creating): array
    {
        $compares = [];
        foreach ($field->constraints() as $constraint) {
            if ($constraint instanceof CompareField && $constraint->context()->appliesTo($creating)) {
                $compares[] = $constraint;
            }
        }

        return $compares;
    }

    private function fieldConstraint(FieldInterface $field, bool $creating): RequiredField|Optional
    {
        $constraints = $this->valueConstraints($field, $creating);

        $isRequired = $this->hasRequired($field, $creating);
        $isNullable = $this->hasNullable($field, $creating);

        if ($isRequired) {
            // Present-and-non-empty on create; if-present-non-empty on update.
            \array_unshift($constraints, new NotBlank(allowNull: $isNullable));
        } elseif (!$isNullable) {
            // A present optional field that is not nullable may not be explicit null.
            \array_unshift($constraints, new NotNull());
        }

        // Presence is only mandatory on create; a partial update never requires a member.
        return $creating && $isRequired
            ? new RequiredField($constraints)
            : new Optional($constraints);
    }

    /**
     * @return list<\Symfony\Component\Validator\Constraint>
     */
    private function valueConstraints(FieldInterface $field, bool $creating): array
    {
        $constraints = [];
        foreach ($field->constraints() as $constraint) {
            if (!$constraint->context()->appliesTo($creating)) {
                continue;
            }
            if ($constraint instanceof Required || $constraint instanceof Nullable) {
                continue; // resolved by fieldConstraint(), not translated as a value rule
            }
            if ($constraint instanceof CompareField) {
                continue; // cross-field; validated at the document level, not per-field
            }
            foreach ($this->translator->translate($constraint) as $symfonyConstraint) {
                $constraints[] = $symfonyConstraint;
            }
        }

        return $constraints;
    }

    private function hasRequired(FieldInterface $field, bool $creating): bool
    {
        foreach ($field->constraints() as $constraint) {
            if ($constraint instanceof Required && $constraint->context()->appliesTo($creating)) {
                return true;
            }
        }

        return false;
    }

    private function hasNullable(FieldInterface $field, bool $creating): bool
    {
        foreach ($field->constraints() as $constraint) {
            if ($constraint instanceof Nullable && $constraint->context()->appliesTo($creating)) {
                return true;
            }
        }

        return false;
    }
}
