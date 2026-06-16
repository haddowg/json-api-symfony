<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\CollectionResult;
use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;

/**
 * A provider deliberately tagged *between* the application default (`0`) and
 * the bundled Doctrine fallback (`-128`) by the
 * {@see ProviderOverrideTestKernel} (`-64`). It exists purely to make the
 * fallback's negative priority observable: it must sort after a
 * default-priority provider but still before the Doctrine provider — and the
 * latter holds only because the bundle registers the fallback below `-64`,
 * never on definition order (a bare-tagged Doctrine provider would tie with
 * the default and outrank this one). Pinned by
 * {@see \haddowg\JsonApiBundle\Tests\Functional\DataProviderPriorityTest}; it
 * never answers a fetch.
 *
 * @implements DataProviderInterface<object>
 */
final class AboveFallbackArticleProvider implements DataProviderInterface
{
    public function supports(string $type): bool
    {
        return $type === 'articles';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return new CollectionResult([]);
    }

    public function fetchRelatedCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): CollectionResult {
        return new CollectionResult([]);
    }
}
