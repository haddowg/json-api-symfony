<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Validation;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\CompareField;
use haddowg\JsonApi\Resource\Constraint\Comparison;
use haddowg\JsonApi\Resource\Constraint\Nullable;
use haddowg\JsonApi\Resource\Constraint\Required;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;
use haddowg\JsonApiBundle\Server\IdEncoderResolver;
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
 * The id format helper ({@see \haddowg\JsonApi\Resource\Field\Id::uuid()} / `ulid()`
 * / `numeric()` / `pattern()`) is validated in **both directions**:
 *  - a client-supplied `data.id` is checked against the *owning* resource's id format
 *    (422 at `/data/id`), the create-direction; and
 *  - each relationship **linkage** id is checked against the *related* type's id
 *    format — a `{ "type": T, "id": X }` reference must match type `T`'s id field
 *    constraints, so a malformed linkage id 422s with a pointer at the linkage
 *    (`/data/relationships/<rel>/data[/<n>]/id`). For a polymorphic relation the
 *    format is resolved from the linkage's own `type` member.
 * Both run on the **wire** id, before any decode. Core only declares the format
 * constraints (on each type's id field); this bridge resolves the relevant type →
 * its resource → id field → constraints through the {@see IdEncoderResolver} and
 * executes them.
 *
 * {@see \haddowg\JsonApi\Resource\Constraint\CompareField} (a cross-field rule) is
 * the exception to per-field validation: it is evaluated at the **document** level,
 * after the {@see Collection} pass, because the comparison needs the sibling field's
 * value — which the `Collection` validates in isolation.
 *
 * A {@see Map} attribute (a structured nested object) validates its child
 * constraints by **recursion**: the bridge builds a nested {@see Collection} from
 * the Map's children that mirrors the top-level one (the same `allowExtraFields`,
 * and per-child {@see Required}/{@see Nullable} resolution by create/update
 * context), so a child violation maps to `/data/attributes/<map>/<child>` — Symfony
 * nests the property path (`[address][postcode]`) and the {@see JsonPointerBuilder}
 * already flattens it. This is an *implicit* cascade: a structured attribute's
 * children validate consistently with top-level attributes, with no `Valid` marker.
 * Only one level deep (a Map's direct children); a child that is itself a Map, and
 * a list-of-objects, are out of scope here (see ADR 0020).
 */
final class ResourceValidator
{
    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly ConstraintTranslator $translator,
        private readonly JsonPointerBuilder $pointers,
        private readonly IdEncoderResolver $idFormats,
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

        $errors = [];

        if ($fields !== []) {
            $violations = $this->validator->validate($attributes, new Collection(fields: $fields, allowExtraFields: true));
            foreach ($violations as $violation) {
                $errors[] = $this->error((string) $violation->getMessage(), (string) $violation->getPropertyPath());
            }

            // Cross-field rules are validated at the document level, where the sibling
            // value is in scope (the per-field Collection sees each value in isolation).
            foreach ($compares as [$owner, $compare]) {
                $error = $this->compareError($owner, $compare, $attributes);
                if ($error !== null) {
                    $errors[] = $error;
                }
            }
        }

        // A client-supplied `data.id` is validated against the owning resource's id
        // format (the same constraints `uuid()`/`ulid()`/`numeric()`/`pattern()`
        // declare) — the create-direction of the format helper, before core decodes
        // or stores it.
        $ownIdError = $this->ownIdError($resource, $data);
        if ($ownIdError !== null) {
            $errors[] = $ownIdError;
        }

        // Relationship linkage ids carry the *related* type's id format (not the
        // owning resource's), so each is validated against that type's id field
        // constraints — before any decode — and a malformed one points at the linkage.
        foreach ($this->linkageErrors($resource, $data) as $error) {
            $errors[] = $error;
        }

        if ($errors === []) {
            return;
        }

        throw new ValidationFailed($errors);
    }

    /**
     * Validates the linkage of a **relationship-mutation endpoint**
     * (`PATCH`/`POST`/`DELETE …/relationships/<rel>`) against the related type's id
     * format — the same wire-id format check a linkage inside a whole-resource write
     * gets ({@see linkageErrors()}), so the two surfaces agree. The body root *is* the
     * relationship here, so a violation points at `/data/id` (to-one) or
     * `/data/<n>/id` (to-many). A polymorphic linkage resolves its format from each
     * member's own `type`; an empty (clearing) linkage has no id to check.
     *
     * @throws ValidationFailed when a linkage id violates the related type's id format
     */
    public function validateRelationshipLinkage(
        RelationInterface $relation,
        ToOneRelationship|ToManyRelationship $linkage,
    ): void {
        $errors = [];

        if ($linkage instanceof ToOneRelationship) {
            $identifier = $linkage->resourceIdentifier;
            if ($identifier !== null) {
                $error = $this->endpointLinkageError($relation, $identifier->type, $identifier->id, null);
                if ($error !== null) {
                    $errors[] = $error;
                }
            }
        } else {
            foreach ($linkage->resourceIdentifiers as $index => $identifier) {
                $error = $this->endpointLinkageError($relation, $identifier->type, $identifier->id, $index);
                if ($error !== null) {
                    $errors[] = $error;
                }
            }
        }

        if ($errors === []) {
            return;
        }

        throw new ValidationFailed($errors);
    }

    /**
     * Validates one parsed relationship-endpoint linkage id against the related type's
     * id format, returning the first violation as an {@see Error} pointed at the
     * endpoint body (`/data/id` or `/data/<index>/id`), or `null` when it passes / has
     * no validatable id. The related type is the linkage's own `type` (so a
     * polymorphic relation resolves the right type), falling back to the relation's
     * single declared related type.
     */
    private function endpointLinkageError(RelationInterface $relation, string $linkageType, ?string $id, ?int $index): ?Error
    {
        if (!\is_string($id) || $id === '') {
            return null; // presence/shape is core's concern, not the format bridge's
        }

        $relatedType = $linkageType !== '' ? $linkageType : ($relation->relatedTypes()[0] ?? null);
        if ($relatedType === null) {
            return null;
        }

        $constraints = $this->formatConstraints($relatedType);
        if ($constraints === []) {
            return null; // the related type's id is unconstrained — any id passes
        }

        $violations = $this->validator->validate($id, $constraints);
        if (\count($violations) === 0) {
            return null;
        }

        return new Error(
            status: '422',
            code: 'VALIDATION_FAILED',
            title: 'Unprocessable Entity',
            detail: (string) $violations->get(0)->getMessage(),
            source: ErrorSource::fromPointer($this->pointers->forRelationshipEndpointLinkageId($index)),
        );
    }

    /**
     * Validates a client-supplied `data.id` against the owning resource's id format —
     * the create-direction twin of the linkage validation. An absent or non-string id
     * is left to core (a generated/store-provided id never reaches here as a wire
     * string); a well-formed-but-format-violating client id 422s with a `/data/id`
     * pointer. A resource whose id declares no format passes any client id.
     *
     * Only validated when the type **accepts** a client id ({@see Id::allowsClientId()}):
     * a forbidden type rejects *any* supplied id outright as core's `403`
     * `ClientGeneratedIdNotSupported`, so format-checking it here would pre-empt that
     * `403` with a `422` for malformed ids only — two statuses for the same forbidden
     * type, depending on a format it ignores. For a forbidden type the format is
     * irrelevant; the `403` is returned uniformly.
     *
     * @param array<string, mixed> $data the write body's `data` member
     */
    private function ownIdError(AbstractResource $resource, array $data): ?Error
    {
        $id = $data['id'] ?? null;
        if (!\is_string($id) || $id === '') {
            return null;
        }

        if (!$this->idFormats->allowsClientIdFor($resource::$type)) {
            return null; // a forbidden type 403s on any supplied id — format is moot
        }

        $constraints = $this->formatConstraints($resource::$type);
        if ($constraints === []) {
            return null;
        }

        $violations = $this->validator->validate($id, $constraints);
        if (\count($violations) === 0) {
            return null;
        }

        return new Error(
            status: '422',
            code: 'VALIDATION_FAILED',
            title: 'Unprocessable Entity',
            detail: (string) $violations->get(0)->getMessage(),
            source: ErrorSource::fromPointer('/data/id'),
        );
    }

    /**
     * Validates each relationship linkage id in the write body against the related
     * type's id format. A monomorphic relation resolves the format from its single
     * declared related type; a polymorphic ({@see \haddowg\JsonApi\Resource\Field\MorphTo})
     * one resolves it from each linkage's own `type` member. A linkage whose `id` is
     * absent or not a string is left to core (presence/shape is the hydrator's
     * concern); a well-formed id that violates the related type's format 422s with a
     * pointer at the linkage. A related type with no declared id format passes any id.
     *
     * @param array<string, mixed> $data the write body's `data` member
     *
     * @return list<Error>
     */
    private function linkageErrors(AbstractResource $resource, array $data): array
    {
        $relationships = $data['relationships'] ?? null;
        if (!\is_array($relationships) || $relationships === []) {
            return [];
        }

        $errors = [];
        foreach ($resource->fields() as $field) {
            if (!$field instanceof RelationInterface) {
                continue;
            }

            $name = $field->name();
            $relationship = $relationships[$name] ?? null;
            if (!\is_array($relationship) || !\array_key_exists('data', $relationship)) {
                continue;
            }

            $linkage = $relationship['data'];
            if ($field->isToMany()) {
                if (!\is_array($linkage)) {
                    continue;
                }
                foreach (\array_values($linkage) as $index => $member) {
                    $error = $this->linkageError($field, $member, $name, $index);
                    if ($error !== null) {
                        $errors[] = $error;
                    }
                }

                continue;
            }

            // A null to-one linkage clears the relationship — there is no id to check.
            if ($linkage === null) {
                continue;
            }
            $error = $this->linkageError($field, $linkage, $name, null);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * Validates one linkage `{type, id}` reference's id against the related type's id
     * format, returning the first violation as an {@see Error} pointed at the linkage
     * (or `null` when it passes / has no validatable id). The related type is the
     * linkage's own `type` member when present (so a polymorphic relation resolves
     * the right type), else the relation's single declared related type.
     */
    private function linkageError(RelationInterface $field, mixed $linkage, string $relation, ?int $index): ?Error
    {
        if (!\is_array($linkage)) {
            return null;
        }

        $id = $linkage['id'] ?? null;
        if (!\is_string($id) || $id === '') {
            return null; // presence/shape is core's concern, not the format bridge's
        }

        $relatedType = \is_string($linkage['type'] ?? null) && $linkage['type'] !== ''
            ? $linkage['type']
            : ($field->relatedTypes()[0] ?? null);
        if ($relatedType === null) {
            return null;
        }

        $constraints = $this->formatConstraints($relatedType);
        if ($constraints === []) {
            return null; // the related type's id is unconstrained — any id passes
        }

        $violations = $this->validator->validate($id, $constraints);
        if (\count($violations) === 0) {
            return null;
        }

        return new Error(
            status: '422',
            code: 'VALIDATION_FAILED',
            title: 'Unprocessable Entity',
            detail: (string) $violations->get(0)->getMessage(),
            source: ErrorSource::fromPointer($this->pointers->forLinkageId($relation, $index)),
        );
    }

    /**
     * The translated Symfony constraints enforcing a type's id format, from the id
     * field's declared format VOs ({@see ConstraintInterface}s the `uuid()` / `ulid()`
     * / `numeric()` / `pattern()` shortcuts append). Empty when the type's id is
     * unconstrained.
     *
     * @return list<\Symfony\Component\Validator\Constraint>
     */
    private function formatConstraints(string $type): array
    {
        $constraints = [];
        foreach ($this->idFormats->formatConstraintsFor($type) as $constraint) {
            foreach ($this->translator->translate($constraint) as $symfonyConstraint) {
                $constraints[] = $symfonyConstraint;
            }
        }

        return $constraints;
    }

    /**
     * The entity-level pass: validates the hydrated entity against the resource's
     * {@see EntityConstraintInterface} constraints (uniqueness and the like, which
     * need the persisted object, not the request document). The write handler calls
     * this after hydration and before commit; it is a no-op for a resource that
     * declares none.
     *
     * @throws ValidationFailed when the entity violates an entity-level constraint
     */
    public function validateEntity(AbstractResource $resource, object $entity, bool $creating): void
    {
        $constraints = [];
        foreach ($resource->fields() as $field) {
            foreach ($field->constraints() as $constraint) {
                if (!$constraint instanceof EntityConstraintInterface || !$constraint->context()->appliesTo($creating)) {
                    continue;
                }
                foreach ($this->translator->translate($constraint) as $symfonyConstraint) {
                    $constraints[] = $symfonyConstraint;
                }
            }
        }

        if ($constraints === []) {
            return;
        }

        $violations = $this->validator->validate($entity, $constraints);
        if (\count($violations) === 0) {
            return;
        }

        $errors = [];
        foreach ($violations as $violation) {
            // An entity violation's path is a bare property name (e.g. `email`);
            // wrap it so the pointer builder maps it to /data/attributes/<name>.
            $errors[] = $this->error((string) $violation->getMessage(), '[' . (string) $violation->getPropertyPath() . ']');
        }

        throw new ValidationFailed($errors);
    }

    private function error(string $detail, string $bracketedPath): Error
    {
        return new Error(
            status: '422',
            code: 'VALIDATION_FAILED',
            title: 'Unprocessable Entity',
            detail: $detail,
            source: ErrorSource::fromPointer($this->pointers->forAttribute($bracketedPath)),
        );
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

        return $this->error(
            \sprintf('This value should be %s the value of "%s".', $this->describe($compare->operator), $compare->field),
            '[' . $owner . ']',
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

    /**
     * @param bool $descend whether to descend into a {@see Map} child's own children;
     *                      true at the top level, false one level in so the cascade
     *                      stops at a Map's direct children (ADR 0020)
     */
    private function fieldConstraint(FieldInterface $field, bool $creating, bool $descend = true): RequiredField|Optional
    {
        $constraints = $this->valueConstraints($field, $creating, $descend);

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
     * @param bool $descend whether a {@see Map} field's children are validated by a
     *                      nested Collection; false stops the cascade one level in
     *
     * @return list<\Symfony\Component\Validator\Constraint>
     */
    private function valueConstraints(FieldInterface $field, bool $creating, bool $descend = true): array
    {
        $constraints = [];

        // A structured attribute (Map) validates its children by recursion: a
        // nested Collection mirroring the top-level one carries the per-child rules,
        // so a child violation maps to /data/attributes/<map>/<child>. One level
        // only — a child that is itself a Map (or a list-of-objects) is not
        // descended into here ($descend is false one level in), which also bounds
        // the recursion (ADR 0020).
        if ($descend && $field instanceof Map) {
            $constraints[] = $this->nestedCollection($field, $creating);
        }

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
            if ($constraint instanceof EntityConstraintInterface) {
                continue; // validated against the hydrated entity, not the document
            }
            foreach ($this->translator->translate($constraint) as $symfonyConstraint) {
                $constraints[] = $symfonyConstraint;
            }
        }

        return $constraints;
    }

    /**
     * Builds the nested {@see Collection} validating a {@see Map}'s direct children,
     * mirroring the top-level attribute Collection: each child is wrapped by the same
     * {@see fieldConstraint()} (so its Required/Optional and Nullable resolution
     * honours the create/update context exactly as a top-level field would), and
     * unknown keys are ignored (`allowExtraFields: true`). The recursion is one level
     * deep by design — a child that is itself a {@see Map}, or a list-of-objects, is
     * out of scope here (ADR 0020), so a nested Map's *own* children are not
     * descended into, which also guards against unbounded recursion.
     */
    private function nestedCollection(Map $map, bool $creating): Collection
    {
        $fields = [];
        foreach ($map->children() as $child) {
            if ($child->isReadOnly($creating)) {
                continue;
            }

            // descend: false — one level only; a nested Map child's own children are
            // not validated here (ADR 0020), which also bounds the recursion.
            $fields[$child->name()] = $this->fieldConstraint($child, $creating, descend: false);
        }

        return new Collection(fields: $fields, allowExtraFields: true);
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
