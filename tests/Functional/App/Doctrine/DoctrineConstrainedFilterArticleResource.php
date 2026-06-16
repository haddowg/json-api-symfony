<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\DataProvider\Doctrine\Filter\WhereHasMatching;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\ConstrainedFilterArticleResource;

/**
 * The Doctrine variant of {@see ConstrainedFilterArticleResource}: the same
 * constraint-bearing `filters()` declaration mapped to {@see ArticleEntity}, so
 * the filter-value-constraint conformance assertions run as real DQL — a
 * mistyped `filter[id]=banana` is rejected with a clean `400` before any query
 * runs, never reaching the data layer.
 *
 * It additionally declares the **Doctrine-only** {@see WhereHasMatching} escape
 * hatch (both surfaces — a structured {@see Criteria} and a raw-subquery closure),
 * recognised only by the Doctrine handler. On the in-memory provider the same
 * `filter[<key>]` is undeclared (the base resource never declares it), so the
 * request is a clean `400` (the unrecognised-filter boundary) — never a silent
 * non-match. The Doctrine-only {@see DoctrineWhereHasMatchingTest} drives it.
 */
#[AsJsonApiResource(entity: ArticleEntity::class)]
final class DoctrineConstrainedFilterArticleResource extends ConstrainedFilterArticleResource
{
    public function filters(): array
    {
        return [
            ...parent::filters(),

            // --- WhereHasMatching (bundle ADR 0069, Doctrine-only) ---
            // PRIMARY surface: a structured Criteria narrowing the related `editors`
            // root — keep an article one of whose editors is named "Ada Lovelace".
            WhereHasMatching::criteria(
                'editorNamed',
                'editors',
                Criteria::create()->where(Criteria::expr()->eq('name', 'Ada Lovelace')),
            ),
            // PRIMARY surface, OR composition: keep an article with an editor named
            // either "Ada Lovelace" OR "Grace Hopper" — the multi-value/OR case the
            // portable WhereThrough vocabulary cannot express.
            WhereHasMatching::criteria(
                'editorEither',
                'editors',
                Criteria::create()->where(
                    Criteria::expr()->orX(
                        Criteria::expr()->eq('name', 'Ada Lovelace'),
                        Criteria::expr()->eq('name', 'Grace Hopper'),
                    ),
                ),
            ),
            // DEEP hatch: a raw-subquery closure parameterised by the request value —
            // a contains-match (LIKE) on the editor name, the author owning the binding.
            WhereHasMatching::using(
                'editorNameLike',
                'editors',
                static function (QueryBuilder $sub, string $relatedAlias, mixed $value): void {
                    $sub->andWhere(\sprintf('%s.name LIKE :editorNameLike', $relatedAlias))
                        ->setParameter('editorNameLike', '%' . (\is_string($value) ? $value : '') . '%');
                },
            ),
        ];
    }
}
