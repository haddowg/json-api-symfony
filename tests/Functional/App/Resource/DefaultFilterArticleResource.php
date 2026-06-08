<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\Filter\Where;

/**
 * The shared `articles` declaration for the filter-default conformance suite:
 * the {@see BaseArticleResource} vocabulary plus a `category` filter that
 * **defaults to `guide`**. With the canonical fixtures (guide × 3, news × 2)
 * the default narrows a bare collection to the three guide rows unless the
 * request overrides it.
 *
 * Not `final` so the Doctrine variant
 * ({@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineDefaultFilterArticleResource})
 * inherits the same `filters()` — both providers run identical assertions
 * ({@see \haddowg\JsonApiBundle\Tests\Functional\FilterDefaultConformanceTestCase}).
 */
class DefaultFilterArticleResource extends BaseArticleResource
{
    public function filters(): array
    {
        return [
            ...parent::filters(),
            Where::make('category')->default('guide'),
        ];
    }
}
