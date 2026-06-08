<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

/**
 * Resolves the {@see DataProviderInterface} for a resource type from the tagged
 * provider services. The {@see \haddowg\JsonApiBundle\Operation\ReadOperationHandler}
 * asks it for a provider, then calls `fetchOne()` / `fetchCollection()`.
 *
 * Resolution is first-`supports()`-match over the injected iteration order. The
 * container supplies the providers sorted by descending tag `priority` (the
 * standard tagged-iterator semantics), and the bundled Doctrine provider — which
 * supports *every* entity-mapped type — registers at `-128`, so an application
 * provider at the default priority (`0`) takes precedence for the types it
 * supports.
 *
 * A type with no matching provider is a wiring error — a registered resource
 * type with no data source — so it raises a {@see \LogicException} (a
 * configuration bug), never a JSON:API error document.
 */
final class DataProviderRegistry
{
    /**
     * @var list<DataProviderInterface<object>>
     */
    private readonly array $providers;

    /**
     * @param iterable<DataProviderInterface<object>> $providers in priority order, highest first
     */
    public function __construct(iterable $providers)
    {
        $this->providers = \is_array($providers) ? \array_values($providers) : \iterator_to_array($providers, false);
    }

    /**
     * The highest-priority provider whose {@see DataProviderInterface::supports()}
     * is true for `$type`.
     *
     * @return DataProviderInterface<object>
     *
     * @throws \LogicException when no registered provider supports the type
     */
    public function forType(string $type): DataProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($type)) {
                return $provider;
            }
        }

        throw new \LogicException(\sprintf('No JSON:API data provider is registered for type "%s".', $type));
    }
}
