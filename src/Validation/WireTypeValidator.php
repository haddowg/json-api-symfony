<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Validation;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\PivotRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\ValueTypeMismatch;
use haddowg\JsonApi\Resource\ValueTypes;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * Refuses a write whose values have the wrong JSON type, with the same `422` the
 * {@see ResourceValidator} gives, for an application without `symfony/validator`. It
 * checks the attributes and each linkage member's pivot values against core's
 * {@see ValueTypes}, and nothing else: the declared constraint rules need the validator.
 * A date's shape is checked by core when the value is cast, before any write hook.
 */
final class WireTypeValidator
{
    public const string NOT_NULL = 'This value should not be null.';

    public const string NOT_AN_OBJECT = 'This value should be an object.';

    public const string WRONG_TYPE = 'This value should be of type {{ type }}.';

    public function __construct(
        private readonly JsonPointerBuilder $pointers = new JsonPointerBuilder(),
    ) {}

    /**
     * The message for a value of the wrong JSON type.
     */
    public static function message(ValueTypeMismatch $mismatch): string
    {
        return match (true) {
            $mismatch->isNull() => self::NOT_NULL,
            $mismatch->expected === ValueTypes::OBJECT => self::NOT_AN_OBJECT,
            default => \str_replace('{{ type }}', $mismatch->expected, self::WRONG_TYPE),
        };
    }

    /**
     * Checks a resource write's attributes and the pivot values of its relationships'
     * linkage. A member already in a relationship, whose stored pivot row is in
     * `$existingPivots`, is checked in the update context and a new one in the create
     * context, as the {@see ResourceValidator} does.
     *
     * @param array<string, array<string, array<string, mixed>>> $existingPivots each pivot relation's stored rows, by member key
     *
     * @throws ValidationFailed
     */
    public function validate(AbstractResource $resource, JsonApiRequestInterface $request, bool $creating, array $existingPivots = []): void
    {
        $data = $request->getResource();
        if (!\is_array($data)) {
            return;
        }

        $attributes = $data['attributes'] ?? null;
        $errors = [];
        foreach (ValueTypes::mismatchesIn($resource->allFields(), \is_array($attributes) ? $attributes : [], $creating, $request) as $mismatch) {
            $errors[] = $this->errorAt(self::message($mismatch), $mismatch->pointer);
        }

        $relationships = $data['relationships'] ?? null;
        foreach ($resource->allFields() as $field) {
            if (!$field instanceof PivotRelationInterface || !\is_array($relationships) || !\is_array($relationships[$field->name()] ?? null)) {
                continue;
            }
            $linkage = $relationships[$field->name()]['data'] ?? null;
            if (!\is_array($linkage)) {
                continue;
            }
            $members = \array_is_list($linkage) ? $linkage : [$linkage];
            foreach ($members as $index => $member) {
                $pointer = fn(string $name): string => $this->pointers->forLinkageMeta($field->name(), '[' . $name . ']', $field->isToMany() ? $index : null);
                $errors = [...$errors, ...$this->memberErrors($field, $member, $existingPivots[$field->name()] ?? [], $pointer)];
            }
        }

        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }
    }

    /**
     * Checks the pivot values of a relationship endpoint's linkage. A removal carries none.
     *
     * @param array<string, array<string, mixed>> $existingPivot the relation's stored pivot rows, by member key
     *
     * @throws ValidationFailed
     */
    public function validateRelationshipLinkage(RelationInterface $relation, ToOneRelationship|ToManyRelationship $linkage, Mode $mode, array $existingPivot = []): void
    {
        if ($mode === Mode::Remove || !$relation instanceof PivotRelationInterface) {
            return;
        }

        $identifiers = $linkage instanceof ToOneRelationship
            ? ($linkage->resourceIdentifier === null ? [] : [null => $linkage->resourceIdentifier])
            : $linkage->resourceIdentifiers;

        $errors = [];
        foreach ($identifiers as $index => $identifier) {
            $position = \is_int($index) ? $index : null;
            $pointer = fn(string $name): string => $this->pointers->forRelationshipEndpointLinkageMeta('[' . $name . ']', $position);
            $member = ['type' => $identifier->type, 'id' => $identifier->id, 'meta' => $identifier->meta];
            $errors = [...$errors, ...$this->memberErrors($relation, $member, $existingPivot, $pointer)];
        }

        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $existingPivot
     * @param \Closure(string): string             $pointer
     *
     * @return list<Error>
     */
    private function memberErrors(PivotRelationInterface&RelationInterface $relation, mixed $member, array $existingPivot, \Closure $pointer): array
    {
        if (!\is_array($member) || $relation->pivotFields() === []) {
            return [];
        }
        $meta = $member['meta'] ?? null;
        $pivot = \is_array($meta) ? $meta['pivot'] ?? null : null;
        if (!\is_array($pivot)) {
            return [];
        }

        $id = $member['id'] ?? null;
        $type = $member['type'] ?? null;
        $key = \is_string($id) ? ($relation instanceof MorphToMany ? (\is_string($type) ? $type : '') . "\0" . $id : $id) : null;
        $creating = $key === null || !\array_key_exists($key, $existingPivot);

        $errors = [];
        foreach ($relation->writablePivotFields($creating) as $field) {
            if (!\array_key_exists($field->name(), $pivot)) {
                continue;
            }
            foreach (ValueTypes::mismatchesOf($field, $pivot[$field->name()], $pointer($field->name()), $creating) as $mismatch) {
                $errors[] = $this->errorAt(self::message($mismatch), $mismatch->pointer);
            }
        }

        return $errors;
    }

    private function errorAt(string $detail, string $pointer): Error
    {
        return ValidationFailed::describe()->toError(
            detail: $detail,
            source: ErrorSource::fromPointer($pointer),
        );
    }
}
