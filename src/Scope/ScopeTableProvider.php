<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Scope;

use haddowg\JsonApi\Scope\OperationScopeResolverInterface;
use haddowg\JsonApi\Scope\ScopeTable;
use haddowg\JsonApi\Scope\ScopeTableBuilder;
use haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource;

/**
 * Each server's {@see ScopeTable}: the one {@see ScopeTableWarmer} wrote into the cache
 * directory, else built from the server's metadata and remembered for the life of the
 * container.
 *
 * The same table feeds enforcement, the OpenAPI document and the operation catalogue, so
 * the three never disagree.
 */
final class ScopeTableProvider
{
    /** The cache file, relative to the cache directory. */
    public const string CACHE_FILE = 'json_api/scope_tables.php';

    /** @var array<string, ScopeTable> */
    private array $tables = [];

    /** @var array<string, ScopeTable>|null */
    private ?array $cached = null;

    /**
     * @param \Closure(): MetadataSource $metadata    the metadata source, read lazily since it reads the servers
     * @param list<string>               $serverNames the declared server names
     * @param string|null                $cacheDir    the cache directory the warmer writes the file under; null reads no cache
     */
    public function __construct(
        private readonly \Closure $metadata,
        private readonly array $serverNames,
        private readonly ?OperationScopeResolverInterface $resolver = null,
        private readonly ?string $cacheDir = null,
    ) {}

    public function forServer(string $server): ScopeTable
    {
        return $this->tables[$server] ??= $this->cachedTable($server) ?? $this->builder($server)->build();
    }

    /**
     * The table the warmer wrote for `$server`, or `null` when there is none.
     */
    public function cachedTable(string $server): ?ScopeTable
    {
        if ($this->cached === null) {
            $this->cached = [];
            $file = $this->cacheDir !== null ? self::path($this->cacheDir) : null;
            $tables = $file !== null && \is_file($file) ? require $file : null;
            foreach (\is_array($tables) ? $tables : [] as $name => $table) {
                if (\is_string($name) && \is_array($table)) {
                    $this->cached[$name] = ScopeTable::fromArray($table);
                }
            }
        }

        return $this->cached[$server] ?? null;
    }

    /**
     * A builder over `$server`'s metadata, which it reads only when it builds or resolves.
     */
    public function builder(string $server): ScopeTableBuilder
    {
        return new ScopeTableBuilder(
            new LazyServerMetadata(fn() => ($this->metadata)()->forServer($server)),
            $this->resolver,
        );
    }

    /**
     * Builds every server's table afresh.
     *
     * @return array<string, ScopeTable>
     *
     * @throws \LogicException when a table cannot be built: the resolver throws, an override names an operation the type does not have, or a public operation needs a scope
     */
    public function buildAll(): array
    {
        $tables = [];
        foreach ($this->serverNames as $server) {
            $tables[$server] = $this->tables[$server] = $this->builder($server)->build();
        }

        return $tables;
    }

    public static function path(string $cacheDir): string
    {
        return $cacheDir . '/' . self::CACHE_FILE;
    }
}
