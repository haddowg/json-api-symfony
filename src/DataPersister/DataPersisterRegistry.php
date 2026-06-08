<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

/**
 * Resolves the {@see DataPersisterInterface} for a resource type from the tagged
 * persister services — the write twin of
 * {@see \haddowg\JsonApiBundle\DataProvider\DataProviderRegistry}. The
 * {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler} asks it for a
 * persister, then calls `create()` / `update()` / `delete()`.
 *
 * Resolution is first-`supports()`-match over the injected iteration order. The
 * container supplies the persisters sorted by descending tag `priority`, and the
 * bundled Doctrine persister — which supports *every* entity-mapped type —
 * registers at `-128`, so an application persister at the default priority (`0`)
 * takes precedence for the types it supports.
 *
 * A type with no matching persister is a wiring error — a resource type writable
 * by no data source — so it raises a {@see \LogicException}, never a JSON:API
 * error document.
 */
final class DataPersisterRegistry
{
    /**
     * @var list<DataPersisterInterface>
     */
    private readonly array $persisters;

    /**
     * @param iterable<DataPersisterInterface> $persisters in priority order, highest first
     */
    public function __construct(iterable $persisters)
    {
        $this->persisters = \is_array($persisters) ? \array_values($persisters) : \iterator_to_array($persisters, false);
    }

    /**
     * The highest-priority persister whose {@see DataPersisterInterface::supports()}
     * is true for `$type`.
     *
     * @throws \LogicException when no registered persister supports the type
     */
    public function forType(string $type): DataPersisterInterface
    {
        foreach ($this->persisters as $persister) {
            if ($persister->supports($type)) {
                return $persister;
            }
        }

        throw new \LogicException(\sprintf('No JSON:API data persister is registered for type "%s".', $type));
    }
}
