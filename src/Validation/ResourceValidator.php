<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Validation;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
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
        foreach ($resource->fields() as $field) {
            if ($field instanceof Id || $field instanceof RelationInterface) {
                continue;
            }
            if ($field->isReadOnly($creating)) {
                continue;
            }

            $fields[$field->name()] = $this->fieldConstraint($field, $creating);
        }

        if ($fields === []) {
            return;
        }

        $violations = $this->validator->validate($attributes, new Collection(fields: $fields, allowExtraFields: true));
        if (\count($violations) === 0) {
            return;
        }

        $errors = [];
        foreach ($violations as $violation) {
            $errors[] = new Error(
                status: '422',
                code: 'VALIDATION_FAILED',
                title: 'Unprocessable Entity',
                detail: (string) $violation->getMessage(),
                source: ErrorSource::fromPointer($this->pointers->forAttribute((string) $violation->getPropertyPath())),
            );
        }

        throw new ValidationFailed($errors);
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
