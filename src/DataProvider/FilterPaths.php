<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\FieldInterface;
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
     * @param list<FieldInterface> $fields the inventory of the type the filters run against
     */
    public function __construct(
        private ThroughPathResolver $resolver,
        private array $fields,
    ) {}

    /**
     * The paths of filters running against `$resource`'s type, read over the types
     * `$resources` registers, or `null` when there is no resource and so no inventory.
     */
    public static function of(SerializerResolverInterface $resources, ?AbstractResource $resource): ?self
    {
        return $resource === null ? null : new self(ThroughPathResolver::forResolver($resources), $resource->allFields());
    }

    /**
     * @throws \LogicException when the path ends on a polymorphic relation or the filter cannot compare what it ends on
     */
    public function resolve(FilterInterface $filter): FilterInterface
    {
        return $this->resolver->resolve($filter, $this->fields);
    }
}
