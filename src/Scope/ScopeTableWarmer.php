<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Scope;

use haddowg\JsonApi\Scope\ScopeTable;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Builds every server's OAuth scope table when the container is dumped and on
 * `cache:warmup`, and writes it to a PHP file the {@see ScopeTableProvider} loads.
 *
 * It is not optional, so a resolver that throws, an override naming an operation the type
 * does not have, or a public operation given a scope fails the build instead of the first
 * request.
 */
final class ScopeTableWarmer implements CacheWarmerInterface
{
    public function __construct(private readonly ScopeTableProvider $tables) {}

    public function isOptional(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $file = ScopeTableProvider::path($cacheDir);
        $directory = \dirname($file);
        if (!\is_dir($directory) && !\mkdir($directory, 0o777, true) && !\is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Cannot create the directory "%s" for the scope tables.', $directory));
        }

        $tables = \array_map(static fn(ScopeTable $table): array => $table->toArray(), $this->tables->buildAll());
        $temporary = $file . '.' . \bin2hex(\random_bytes(4));
        if (\file_put_contents($temporary, '<?php return ' . \var_export($tables, true) . ";\n") === false || !\rename($temporary, $file)) {
            throw new \RuntimeException(\sprintf('Cannot write the scope tables to "%s".', $file));
        }

        return [];
    }
}
