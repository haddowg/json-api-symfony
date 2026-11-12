<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\CatalogConsumer;

use haddowg\JsonApi\Catalog\OperationCatalog;
use haddowg\JsonApiBundle\DataPersister\AbstractDataPersister;

/**
 * A persister that depends on the {@see OperationCatalog}, the write-side twin of the
 * {@see CapabilityProvider}. It refuses every write: only its construction matters.
 */
final class CapabilityPersister extends AbstractDataPersister
{
    public function __construct(public readonly OperationCatalog $catalog) {}

    public function supports(string $type): bool
    {
        return $type === CapabilityResource::$type;
    }

    public function instantiate(string $type): object
    {
        return new Capability();
    }

    public function create(string $type, object $entity): object
    {
        throw new \LogicException('Capabilities are read-only.');
    }

    public function update(string $type, object $entity): object
    {
        throw new \LogicException('Capabilities are read-only.');
    }

    public function delete(string $type, object $entity): void
    {
        throw new \LogicException('Capabilities are read-only.');
    }
}
