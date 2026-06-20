<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApiBundle\Tests\Functional\App\Query\OrderByRelationCount;
use haddowg\JsonApiBundle\Tests\Functional\App\Query\RelationCountAtLeast;

/**
 * A shared `articles` declaration for the extensible-handler-seam conformance suite:
 * the {@see BaseArticleResource} vocabulary plus a **custom filter and sort** that
 * neither built-in handler recognises —
 *  - `filter[minComments]=N` ({@see RelationCountAtLeast}) keeps an article with at
 *    least `N` comments;
 *  - `sort=commentCount` ({@see OrderByRelationCount}) orders by comment count.
 *
 * Both run only because a registered arm teaches each provider to execute them (the
 * in-memory arm is the conformance witness, the Doctrine arm the push-down), so the
 * same assertions select/order identically on both providers. The `id` field sort is
 * declared as a deterministic tie-breaker.
 *
 * Not `final` so the Doctrine variant
 * ({@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineRelationCountArticleResource})
 * inherits the same declarations.
 */
class RelationCountArticleResource extends BaseArticleResource
{
    public function filters(): array
    {
        return [
            RelationCountAtLeast::make('minComments', 'comments'),
        ];
    }

    public function sorts(): array
    {
        return [
            OrderByRelationCount::make('commentCount', 'comments'),
            // A second count sort over a different to-many (the many-to-many `editors`),
            // so `sort=commentCount,editorCount` exercises two applications of the same
            // arm in one request — proving each emits a distinct push-down alias.
            OrderByRelationCount::make('editorCount', 'editors'),
            SortByField::make('id'),
        ];
    }
}
