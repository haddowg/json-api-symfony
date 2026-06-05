<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

/**
 * Resolves the {@see DataProviderInterface} for a resource type from the tagged
 * provider services. The {@see \haddowg\JsonApiBundle\Operation\ReadOperationHandler}
 * asks it for a provider, then calls `fetchOne()` / `fetchCollection()`.
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
     * @param iterable<DataProviderInterface<object>> $providers
     */
    public function __construct(iterable $providers)
    {
        $this->providers = \is_array($providers) ? \array_values($providers) : \iterator_to_array($providers, false);
    }

    /**
     * The provider whose {@see DataProviderInterface::supports()} is true for
     * `$type`.
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
