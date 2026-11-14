<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\ThroughPathResolver;
use haddowg\JsonApi\Resource\SerializerResolverInterface;

/**
 * Resolves a traversal filter's path against the type its filters run against, through
 * core's {@see ThroughPathResolver}: the primary type on a collection fetch, the related
 * type on a related or relationship endpoint. The {@see CriteriaApplier} and the filter
 * value validator resolve each filter after matching the requested key, so a filter
 * family's filter resolves per request, and a path ending on a relationship compares
 * the related resource's id.
 */
final readonly class FilterPaths
{
    /**
     * @param list<FieldInterface>                                     $fields   the inventory of the type the filters run against
     * @param (\Closure(string): (iterable<FieldInterface>|null))|null $fieldsOf a related type's inventory, or `null` when the type has none
     * @param string|null                                              $type     the type the filters run against
     */
    public function __construct(
        private ThroughPathResolver $resolver,
        private array $fields,
        private ?\Closure $fieldsOf = null,
        private ?string $type = null,
    ) {}

    /**
     * The paths of filters running against `$resource`'s type, read over the types
     * `$resources` registers, or `null` when there is no resource and so no inventory.
     */
    public static function of(SerializerResolverInterface $resources, ?AbstractResource $resource): ?self
    {
        if ($resource === null) {
            return null;
        }

        return new self(ThroughPathResolver::forResolver($resources), $resource->allFields(), static function (string $type) use ($resources): ?array {
            if (!$resources->hasSerializerFor($type)) {
                return null;
            }

            $serializer = $resources->serializerFor($type);

            return $serializer instanceof AbstractResource ? $serializer->allFields() : null;
        }, $resource::$type);
    }

    /**
     * The type the filters run against, whose id encoder decodes the ids they compare
     * with its own, or null when the paths were built without one.
     */
    public function type(): ?string
    {
        return $this->type;
    }

    /**
     * The declared relation each leading segment of a dotted storage path walks, from the
     * type the filters run against: the relation whose `column()` is the segment, then
     * the next segment on that relation's related type. The list stops at the first
     * segment that is not a monomorphic relation, so a path ending on a field has one
     * entry fewer than it has segments.
     *
     * @return list<RelationInterface>
     */
    public function relationsAlong(string $path): array
    {
        $relations = [];
        $fields = $this->fields;
        foreach (\explode('.', $path) as $segment) {
            $relation = null;
            foreach ($fields as $field) {
                if ($field instanceof RelationInterface && $field->column() === $segment) {
                    $relation = $field;

                    break;
                }
            }
            if ($relation === null) {
                break;
            }

            $relations[] = $relation;
            $related = $relation->relatedTypes();
            $next = \count($related) === 1 && $this->fieldsOf !== null ? ($this->fieldsOf)($related[0]) : null;
            if ($next === null) {
                break;
            }
            $fields = \is_array($next) ? \array_values($next) : \iterator_to_array($next, false);
        }

        return $relations;
    }

    /**
     * @throws \LogicException when the path ends on a polymorphic relation or the filter cannot compare what it ends on
     */
    public function resolve(FilterInterface $filter): FilterInterface
    {
        return $this->resolver->resolve($filter, $this->fields);
    }
}
