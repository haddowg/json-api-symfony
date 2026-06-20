<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Validation;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\CompareField;
use haddowg\JsonApi\Resource\Constraint\Comparison;
use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Constraint\Nullable;
use haddowg\JsonApi\Resource\Constraint\Required;
use haddowg\JsonApi\Resource\Constraint\When;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Mode;
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
     * Validates a create/update document against the resource's declared
     * constraints. On an **update** ($creating === false) the existing domain
     * object — already loaded by the handler — is folded in: the wire-form
     * attribute map of the stored resource is **merged** under the incoming
     * partial (an incoming key overrides per key; a key absent from the partial
     * keeps its stored value), and that **merged** map is what both the per-field
     * Collection and the cross-field comparison see. So a cross-field or
     * conditional rule that depends on a sibling the partial did not re-send
     * (e.g. `expiresAt` must be after a stored `publishedAt`) evaluates against
     * the resulting resource state, not the partial-only picture — and a
     * required-on-update field the client legitimately omitted but that is present
     * in stored state no longer spuriously fails (the merged map carries it). A
     * stored value resolving to `null` is **not** folded in (it carries no
     * value to evaluate, and folding it would flip an absent optional into a
     * present-null that trips `NotNull`/the comparison), so it is dropped before
     * the merge — an incoming explicit `null` still overrides because the
     * incoming partial is merged last. On create there is no existing object and
     * the incoming document is validated as today (merge-before-validate, ADR
     * 0049 [HALF A]).
     *
     * For a `belongsToMany` pivot relation present in the write body, the existing
     * pivot rows (keyed by relation name, read by the handler through the
     * {@see \haddowg\JsonApiBundle\DataProvider\DataProviderInterface::fetchRelationshipPivot()}
     * seam) are folded the same way **per member**: an incoming member already in the
     * relationship validates its MERGED pivot (stored row overlaid by the incoming
     * meta) in the update context, a genuinely-new member validates its incoming meta
     * in the create (new-row) context — superseding the always-create-context band-aid
     * (ADR 0050 [HALF B]).
     *
     * @param object|null                                        $existingObject the already-loaded domain object on an
     *                                                                           update (null on create), whose stored
     *                                                                           attribute values are folded under the
     *                                                                           incoming partial before validation
     * @param array<string, array<string, array<string, mixed>>> $existingPivots the existing pivot meta of each pivot
     *                                                                           relation in the body, keyed by relation
     *                                                                           name then by related id (empty on create,
     *                                                                           or for a provider that stores no pivot)
     *
     * @throws ValidationFailed when the document violates the resource's constraints
     */
    public function validate(
        AbstractResource $resource,
        JsonApiRequestInterface $request,
        bool $creating,
        ?object $existingObject = null,
        array $existingPivots = [],
    ): void {
        $data = $request->getResource();
        if (!\is_array($data)) {
            return; // a missing/malformed data member is core's concern, raised in hydration
        }

        $incoming = $data['attributes'] ?? [];
        if (!\is_array($incoming)) {
            return;
        }

        // On update, fold the stored resource's wire-form attributes under the
        // incoming partial so the constraints see the MERGED resource state.
        $attributes = !$creating && $existingObject !== null
            ? \array_merge($this->storedAttributes($resource, $existingObject, $request), $incoming)
            : $incoming;

        $fields = [];
        $compares = [];
        foreach ($resource->fields() as $field) {
            if ($field instanceof Id || $field instanceof RelationInterface) {
                continue;
            }
            // The read-only gate is request-aware (core ADR 0079): a field the author
            // marked `readOnly(fn)` is validated for a caller it is *writable* for and
            // skipped for one it is *read-only* for — so validation and hydration stay
            // consistent. Were this static, a conditionally-read-only field would be
            // validated (e.g. required) but then never hydrated for the same caller,
            // surfacing a spurious 422 the write could never satisfy.
            if ($field->isReadOnlyFor($creating, $request)) {
                continue;
            }

            // The field's incoming (merged) value, or null when absent — used to
            // evaluate a request-aware `when()` condition during presence resolution
            // (a conditionally-required field, ADR 0084).
            $value = $attributes[$field->name()] ?? null;
            $fields[$field->name()] = $this->fieldConstraint($field, $creating, request: $request, value: $value);
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
        // A belongsToMany pivot relation also validates each member's pivot `meta`
        // against the relation's WRITABLE pivot fields' constraints (a readOnly pivot
        // field supplied in meta is ignored), in the per-member new/existing context:
        // a member already in the relationship merges its stored pivot row under the
        // incoming meta and validates it in the UPDATE context (a writable field absent
        // from meta keeps its stored value — the persister preserves it), while a
        // genuinely-new member validates the incoming meta in the CREATE (new-row)
        // context (a required writable field absent on a new row is still a `422`). The
        // member is "existing" when its related id is in `$existingPivots[<relation>]`.
        foreach ($this->linkageErrors($resource, $data, $existingPivots) as $error) {
            $errors[] = $error;
        }

        if ($errors === []) {
            return;
        }

        throw new ValidationFailed($errors);
    }

    /**
     * The stored resource's wire-form attribute map: resolves each attribute's
     * serializer closure ({@see AbstractResource::getAttributes()}) against the
     * already-loaded domain object, yielding the same wire representation a read
     * would render. A value resolving to `null` is omitted (a stored null carries
     * no value to fold and would convert an absent optional into a present-null
     * that trips `NotNull`/the cross-field comparison); an incoming explicit null
     * still overrides because the partial is merged on top.
     *
     * @return array<string, mixed> the non-null stored attribute wire values, by name
     */
    private function storedAttributes(AbstractResource $resource, object $existingObject, JsonApiRequestInterface $request): array
    {
        $stored = [];
        foreach ($resource->getAttributes($existingObject, $request) as $name => $resolve) {
            $value = $resolve($existingObject, $request, $name);
            if ($value === null) {
                continue;
            }

            $stored[$name] = $value;
        }

        return $stored;
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
     * An add ({@see Mode::Add}) or replace ({@see Mode::Replace}) of a pivot relation
     * validates each member's pivot `meta` in the per-member new/existing context: a
     * member whose related id is in `$existingPivot` is already in the relationship, so
     * its stored pivot row is merged under the incoming meta and validated in the
     * UPDATE context (a writable field absent from meta keeps its stored value — the
     * persister reorders the row in place, ADR 0046), while a genuinely-new member
     * validates the incoming meta in the CREATE (new-row) context — a required writable
     * pivot field absent on a new row is a `422` before persist (never the DB NOT-NULL
     * `500`). This supersedes the always-create-context band-aid (ADR 0050).
     * {@see Mode::Remove} carries no pivot meta, so no pivot validation runs.
     *
     * @param array<string, array<string, mixed>> $existingPivot the relation's existing pivot rows, by related id
     *                                                            (read by the handler through the provider seam; empty
     *                                                            when the relation stores no pivot, so every member is new)
     *
     * @throws ValidationFailed when a linkage id violates the related type's id format
     */
    public function validateRelationshipLinkage(
        RelationInterface $relation,
        ToOneRelationship|ToManyRelationship $linkage,
        Mode $mode = Mode::Replace,
        array $existingPivot = [],
    ): void {
        $errors = [];

        $isPivot = $mode !== Mode::Remove && $relation instanceof BelongsToMany && $relation->pivotFields() !== [];

        if ($linkage instanceof ToOneRelationship) {
            $identifier = $linkage->resourceIdentifier;
            if ($identifier !== null) {
                $error = $this->endpointLinkageError($relation, $identifier->type, $identifier->id, null);
                if ($error !== null) {
                    $errors[] = $error;
                }
                if ($isPivot && $relation instanceof BelongsToMany) {
                    foreach ($this->endpointMemberPivotErrors($relation, $identifier->id, $identifier->meta, null, $existingPivot) as $metaError) {
                        $errors[] = $metaError;
                    }
                }
            }
        } else {
            foreach ($linkage->resourceIdentifiers as $index => $identifier) {
                $error = $this->endpointLinkageError($relation, $identifier->type, $identifier->id, $index);
                if ($error !== null) {
                    $errors[] = $error;
                }
                if ($isPivot && $relation instanceof BelongsToMany) {
                    foreach ($this->endpointMemberPivotErrors($relation, $identifier->id, $identifier->meta, $index, $existingPivot) as $metaError) {
                        $errors[] = $metaError;
                    }
                }
            }
        }

        if ($errors === []) {
            return;
        }

        throw new ValidationFailed($errors);
    }

    /**
     * Validates ONE relationship-endpoint member's pivot meta in the per-member
     * new/existing context (ADR 0050): a member whose related id is in `$existingPivot`
     * is an existing row, so its stored pivot row is merged under the incoming meta and
     * validated in the UPDATE context (a writable field absent from meta keeps its
     * stored value); a member whose id is absent is a new row, validated in the CREATE
     * context. Runs both the per-field pivot Collection and the cross-pivot-field
     * comparisons over the merged meta, pointed at the endpoint linkage meta.
     *
     * @param array<string, mixed>                 $incoming      the parsed per-member pivot meta
     * @param array<string, array<string, mixed>>  $existingPivot the relation's existing pivot rows, by related id
     *
     * @return list<Error>
     */
    private function endpointMemberPivotErrors(BelongsToMany $relation, ?string $id, array $incoming, ?int $index, array $existingPivot): array
    {
        $stored = \is_string($id) ? ($existingPivot[$id] ?? null) : null;
        $creating = $stored === null;
        $meta = $stored === null ? $incoming : \array_merge($stored, $incoming);

        $errors = [];
        foreach ($this->endpointPivotMetaErrors($this->pivotMetaCollection($relation, $creating), $meta, $index) as $metaError) {
            $errors[] = $metaError;
        }
        foreach ($this->pivotCompareErrors(
            $relation,
            $meta,
            $creating,
            fn(string $owner): string => $this->pointers->forRelationshipEndpointLinkageMeta($owner, $index),
        ) as $compareError) {
            $errors[] = $compareError;
        }

        return $errors;
    }

    /**
     * Validates one relationship-endpoint linkage member's pivot `meta` against the
     * relation's writable pivot fields (the pre-built `$collection`), returning one
     * {@see Error} per violation pointed at the endpoint linkage meta
     * (`/data[/<index>]/meta/<field>`). A `null` collection (non-pivot relation, or no
     * writable pivot field) yields no errors; a readOnly pivot field supplied in meta
     * is ignored (extra fields allowed), exactly as on the whole-resource path.
     *
     * @param array<string, mixed> $meta the parsed per-member pivot meta
     *
     * @return list<Error>
     */
    private function endpointPivotMetaErrors(?Collection $collection, array $meta, ?int $index): array
    {
        if ($collection === null) {
            return [];
        }

        $errors = [];
        foreach ($this->validator->validate($meta, $collection) as $violation) {
            $errors[] = new Error(
                status: '422',
                code: 'VALIDATION_FAILED',
                title: 'Unprocessable Entity',
                detail: (string) $violation->getMessage(),
                source: ErrorSource::fromPointer(
                    $this->pointers->forRelationshipEndpointLinkageMeta((string) $violation->getPropertyPath(), $index),
                ),
            );
        }

        return $errors;
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
     * @param array<string, mixed>                               $data           the write body's `data` member
     * @param array<string, array<string, array<string, mixed>>> $existingPivots the existing pivot meta per relation name, by related id
     *
     * @return list<Error>
     */
    private function linkageErrors(AbstractResource $resource, array $data, array $existingPivots = []): array
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

            $existingPivot = $existingPivots[$name] ?? [];

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
                    foreach ($this->pivotMetaErrors($field, $member, $name, $index, $existingPivot) as $metaError) {
                        $errors[] = $metaError;
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
            foreach ($this->pivotMetaErrors($field, $linkage, $name, null, $existingPivot) as $metaError) {
                $errors[] = $metaError;
            }
        }

        return $errors;
    }

    /**
     * Validates one linkage member's pivot `meta` against the relation's WRITABLE
     * pivot fields' constraints, returning one {@see Error} per violation pointed at
     * the linkage meta (`/data/relationships/<rel>/data[/<index>]/meta/<field>`). A
     * non-pivot relation, a non-array member, or a member with no `meta` yields no
     * errors. A readOnly pivot field supplied in `meta` is ignored — it is not in the
     * writable set the Collection validates, and the Collection allows extra fields —
     * so it never raises a violation (consistent with how a readOnly attribute is
     * handled).
     *
     * The context resolves **per member**: a member whose related id is in
     * `$existingPivot` is already in the relationship, so its stored pivot row is
     * merged under the incoming meta (`array_merge(stored, incoming)`) and validated in
     * the UPDATE context — a writable field absent from meta keeps its stored value
     * (the persister preserves it in place), so it does not spuriously fail a
     * required-on-create rule. A member whose related id is absent is a genuinely-new
     * association row, validated in the CREATE (new-row) context — a required writable
     * pivot field absent on it is a `422` (a new row would have no value for it), never
     * the DB NOT-NULL `500`. This supersedes the always-create-context band-aid the
     * pivot-writes work introduced (ADR 0050).
     *
     * @param array<mixed, mixed>|mixed                $member        the raw linkage member (`{type, id, meta?}`)
     * @param array<string, array<string, mixed>>      $existingPivot the relation's existing pivot rows, by related id
     *
     * @return list<Error>
     */
    private function pivotMetaErrors(RelationInterface $relation, mixed $member, string $name, ?int $index, array $existingPivot = []): array
    {
        if (!$relation instanceof BelongsToMany || $relation->pivotFields() === []) {
            return [];
        }
        if (!\is_array($member)) {
            return [];
        }

        $meta = $member['meta'] ?? [];
        if (!\is_array($meta)) {
            $meta = [];
        }

        // A member already in the relationship merges its stored pivot row under the
        // incoming meta and validates in the update context; a new member validates
        // the incoming meta alone in the create context.
        $id = $member['id'] ?? null;
        $stored = \is_string($id) ? ($existingPivot[$id] ?? null) : null;
        $creating = $stored === null;
        $meta = $stored === null ? $meta : \array_merge($stored, $meta);

        $collection = $this->pivotMetaCollection($relation, $creating);
        if ($collection === null) {
            return []; // no writable pivot fields → nothing to validate
        }

        $errors = [];
        foreach ($this->validator->validate($meta, $collection) as $violation) {
            $errors[] = new Error(
                status: '422',
                code: 'VALIDATION_FAILED',
                title: 'Unprocessable Entity',
                detail: (string) $violation->getMessage(),
                source: ErrorSource::fromPointer(
                    $this->pointers->forLinkageMeta($name, (string) $violation->getPropertyPath(), $index),
                ),
            );
        }

        // Cross-pivot-field comparisons over the merged meta (the pivot analogue of the
        // attribute compare loop), pointed at the linkage meta.
        foreach ($this->pivotCompareErrors(
            $relation,
            $meta,
            $creating,
            fn(string $owner): string => $this->pointers->forLinkageMeta($name, $owner, $index),
        ) as $compareError) {
            $errors[] = $compareError;
        }

        return $errors;
    }

    /**
     * Builds the {@see Collection} validating a pivot relation's WRITABLE pivot
     * fields' `meta`, mirroring the top-level attribute Collection: each writable
     * field is wrapped by the same {@see fieldConstraint()} (so its
     * Required/Optional and Nullable resolution honours the create/update context),
     * and unknown keys — including a supplied readOnly pivot field — are ignored
     * (`allowExtraFields: true`). Returns `null` when the relation has no writable
     * pivot field in this context (nothing to validate).
     */
    private function pivotMetaCollection(BelongsToMany $relation, bool $creating): ?Collection
    {
        $fields = [];
        foreach ($relation->writablePivotFields($creating) as $field) {
            $fields[$field->name()] = $this->fieldConstraint($field, $creating);
        }

        return $fields === [] ? null : new Collection(fields: $fields, allowExtraFields: true);
    }

    /**
     * Validates the cross-field comparisons ({@see CompareField}) a relation's writable
     * pivot fields declare, over the **merged** pivot meta — the document-level analogue
     * of the attribute {@see compareError()} loop, scoped to pivot meta. Because the
     * meta is already merged (stored row overlaid by incoming, ADR 0050), a comparison
     * against a sibling pivot field the partial did not re-send evaluates against the
     * member's resulting pivot state, not the partial alone. The pointer is built by the
     * caller's `$pointer` closure so the same machinery serves the whole-resource linkage
     * pointer (`/data/relationships/<rel>/data[/<n>]/meta/<owner>`) and the
     * relationship-endpoint one (`/data[/<n>]/meta/<owner>`).
     *
     * @param array<string, mixed>      $meta    the merged per-member pivot meta
     * @param \Closure(string): string  $pointer maps a bracketed owner (`[position]`) to its meta pointer
     *
     * @return list<Error>
     */
    private function pivotCompareErrors(BelongsToMany $relation, array $meta, bool $creating, \Closure $pointer): array
    {
        $errors = [];
        foreach ($relation->writablePivotFields($creating) as $field) {
            foreach ($this->compareConstraints($field, $creating) as $compare) {
                $detail = $this->compareViolation($field->name(), $compare, $meta);
                if ($detail === null) {
                    continue;
                }

                $errors[] = new Error(
                    status: '422',
                    code: 'VALIDATION_FAILED',
                    title: 'Unprocessable Entity',
                    detail: $detail,
                    source: ErrorSource::fromPointer($pointer('[' . $field->name() . ']')),
                );
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
        $detail = $this->compareViolation($owner, $compare, $attributes);

        return $detail === null ? null : $this->error($detail, '[' . $owner . ']');
    }

    /**
     * The detail message of a violated cross-field comparison, or `null` when the
     * comparison holds or cannot run (a value absent or null — presence is the Required
     * rule's concern, not this one's). Context-free so both the attribute path (which
     * points at `/data/attributes/<owner>`) and the pivot-meta path (which points at a
     * linkage meta pointer) reuse the same comparison and message.
     *
     * @param array<mixed, mixed> $values
     */
    private function compareViolation(string $owner, CompareField $compare, array $values): ?string
    {
        if (!\array_key_exists($owner, $values) || !\array_key_exists($compare->field, $values)) {
            return null;
        }

        $value = $values[$owner];
        $other = $values[$compare->field];
        if ($value === null || $other === null || $this->satisfies($compare->operator, $value, $other)) {
            return null;
        }

        return \sprintf('This value should be %s the value of "%s".', $this->describe($compare->operator), $compare->field);
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
     * @param ?JsonApiRequestInterface $request the inbound request threaded into a
     *                                           widened {@see \haddowg\JsonApi\Resource\Constraint\When}
     *                                           condition (null on the filter/pivot/id paths — the
     *                                           documented MVP boundary)
     * @param mixed $value the field's incoming value (or null when absent), used to
     *                     evaluate a widened `when()` condition during presence
     *                     resolution so a *conditionally*-required field (a `Required`
     *                     wrapped in a `When` whose condition holds for the caller) is
     *                     required for the matching caller (ADR 0084)
     */
    private function fieldConstraint(FieldInterface $field, bool $creating, bool $descend = true, ?JsonApiRequestInterface $request = null, mixed $value = null): RequiredField|Optional
    {
        $constraints = $this->valueConstraints($field, $creating, $descend, $request);

        $isRequired = $this->hasRequired($field, $creating, $request, $value);
        $isNullable = $this->hasNullable($field, $creating, $request, $value);

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
     * @param ?JsonApiRequestInterface $request threaded into a widened {@see \haddowg\JsonApi\Resource\Constraint\When}
     *                                           condition (null on the filter/pivot/id paths)
     *
     * @return list<\Symfony\Component\Validator\Constraint>
     */
    private function valueConstraints(FieldInterface $field, bool $creating, bool $descend = true, ?JsonApiRequestInterface $request = null): array
    {
        $constraints = [];

        // A structured attribute (Map) validates its children by recursion: a
        // nested Collection mirroring the top-level one carries the per-child rules,
        // so a child violation maps to /data/attributes/<map>/<child>. One level
        // only — a child that is itself a Map (or a list-of-objects) is not
        // descended into here ($descend is false one level in), which also bounds
        // the recursion (ADR 0020).
        if ($descend && $field instanceof Map) {
            $constraints[] = $this->nestedCollection($field, $creating, $request);
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
            foreach ($this->translator->translate($constraint, $request) as $symfonyConstraint) {
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
    private function nestedCollection(Map $map, bool $creating, ?JsonApiRequestInterface $request = null): Collection
    {
        $fields = [];
        foreach ($map->children() as $child) {
            // A Map child's *visibility* (read-only / hidden) is an explicit non-goal
            // of request-aware predicates (ADR 0020 / core ADR 0079), so this skip
            // stays static; only the child's `when()` condition sees the request,
            // which is why the request still threads into fieldConstraint() below.
            if ($child->isReadOnly($creating)) {
                continue;
            }

            // descend: false — one level only; a nested Map child's own children are
            // not validated here (ADR 0020), which also bounds the recursion.
            $fields[$child->name()] = $this->fieldConstraint($child, $creating, descend: false, request: $request);
        }

        return new Collection(fields: $fields, allowExtraFields: true);
    }

    /**
     * Whether the field is required in this context — resolving a {@see Required}
     * declared directly **or** wrapped in a {@see When} whose (widened) condition
     * holds for this caller and value (ADR 0084). The latter is how a *conditionally*
     * required field — `when(fn($v, $req) => …, fn($f) => $f->required())` — gates
     * presence on the request: an admin omitting it then 422s while another caller is
     * unaffected. The `When` condition receives the incoming value (null when absent)
     * and the request, matching the execution-site signature.
     */
    private function hasRequired(FieldInterface $field, bool $creating, ?JsonApiRequestInterface $request = null, mixed $value = null): bool
    {
        return $this->hasPresenceRule($field, Required::class, $creating, $request, $value);
    }

    private function hasNullable(FieldInterface $field, bool $creating, ?JsonApiRequestInterface $request = null, mixed $value = null): bool
    {
        return $this->hasPresenceRule($field, Nullable::class, $creating, $request, $value);
    }

    /**
     * Whether a presence rule of `$ruleClass` ({@see Required}/{@see Nullable}) applies
     * in this context, looking both at the field's own constraints and inside any
     * {@see When} whose condition holds for the caller/value — the seam that lets a
     * `when()`-wrapped presence rule be request-aware (ADR 0084).
     *
     * @param class-string<ConstraintInterface> $ruleClass
     */
    private function hasPresenceRule(FieldInterface $field, string $ruleClass, bool $creating, ?JsonApiRequestInterface $request, mixed $value): bool
    {
        foreach ($field->constraints() as $constraint) {
            if (!$constraint->context()->appliesTo($creating)) {
                continue;
            }
            if ($constraint instanceof $ruleClass) {
                return true;
            }
            // A presence rule nested in a `When` applies only when the condition
            // holds for this caller — the request-aware conditional-required seam.
            if ($constraint instanceof When && ($constraint->condition)($value, $request) === true) {
                foreach ($constraint->constraints as $inner) {
                    if ($inner instanceof $ruleClass && $inner->context()->appliesTo($creating)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
