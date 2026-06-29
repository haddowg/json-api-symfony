<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Query;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineFilterArmInterface;

/**
 * The Doctrine arm for {@see FullTextSearch}: pushes the search down to DQL as a
 * single OR of `LOWER(<alias>.<field>) LIKE :term` predicates over the filter's
 * declared fields — one bound parameter, no join, case-insensitive. Discovered by
 * plain autoconfiguration (the bundle tags any {@see DoctrineFilterArmInterface}),
 * so it needs no service definition beyond the example's `src/` registration.
 *
 * The placeholder name is derived off the running parameter count (and avoids the
 * reserved `jsonapi_` prefix the bundle's own bindings use) so it never collides
 * when the same request carries other filters.
 */
final class DoctrineFullTextSearchArm implements DoctrineFilterArmInterface
{
    public function supports(FilterInterface $filter): bool
    {
        return $filter instanceof FullTextSearch;
    }

    public function apply(FilterInterface $filter, QueryBuilder $query, mixed $value, string $alias): void
    {
        \assert($filter instanceof FullTextSearch);

        $term = \is_scalar($value) ? \trim((string) $value) : '';
        if ($term === '' || $filter->fields === []) {
            return;
        }

        // Escape the LIKE metacharacters and ASCII-lowercase the value so it folds
        // identically to DQL `LOWER()` — mirroring the bundle's built-in likeMatch
        // contract, so a `%`/`_` the user types is matched literally (not as a wildcard).
        $escaped = \str_replace(['!', '%', '_'], ['!!', '!%', '!_'], \strtolower($term));

        $name = 'fulltext_' . \count($query->getParameters());
        $predicates = \array_map(
            static fn(string $field): string => \sprintf("LOWER(%s.%s) LIKE :%s ESCAPE '!'", $alias, $field, $name),
            $filter->fields,
        );

        $query
            ->andWhere($query->expr()->orX(...$predicates))
            ->setParameter($name, '%' . $escaped . '%');
    }
}
