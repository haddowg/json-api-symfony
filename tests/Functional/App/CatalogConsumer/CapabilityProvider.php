<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\CatalogConsumer;

use haddowg\JsonApi\Catalog\OperationCatalog;
use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApiBundle\DataProvider\AbstractDataProvider;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Serves the operations the {@see OperationCatalog} lists as `capabilities` rows, so the
 * provider depends on the catalogue the bundle's metadata builds.
 *
 * @extends AbstractDataProvider<object>
 */
final class CapabilityProvider extends AbstractDataProvider
{
    private ?InMemoryDataProvider $rows = null;

    public function __construct(private readonly OperationCatalog $catalog) {}

    public function supports(string $type): bool
    {
        return $type === CapabilityResource::$type;
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return $this->rows()->fetchOne($type, $id);
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return $this->rows()->fetchCollection($type, $criteria);
    }

    private function rows(): InMemoryDataProvider
    {
        if ($this->rows === null) {
            $rows = [];
            foreach ($this->catalog->forServer('default') as $operation) {
                $rows[$operation->operationId] = new Capability($operation->operationId, $operation->method);
            }
            $this->rows = new InMemoryDataProvider(CapabilityResource::$type, $rows);
        }

        return $this->rows;
    }
}
