<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\QueryBuilder;

/**
 * Hands out the DQL aliases query extensions ask for through
 * {@see ExtensionContext::uniqueAlias()}, unique within one statement. The query that
 * executes and every subquery the bundle embeds in it for extensions share one
 * instance, so an extension that runs on the root and again inside a scoped hop gets a
 * different alias each time.
 *
 * @internal
 */
final class ExtensionAliases
{
    /**
     * @var \WeakMap<QueryBuilder, self>|null
     */
    private static ?\WeakMap $statements = null;

    /**
     * @var array<string, int>
     */
    private array $issued = [];

    /**
     * The aliases of the statement `$statement` executes.
     */
    public static function of(QueryBuilder $statement): self
    {
        self::$statements ??= new \WeakMap();

        return self::$statements[$statement] ??= new self();
    }

    /**
     * The aliases of `$statement`, also used from now on for `$embedded`, a builder
     * whose DQL ends up inside `$statement`'s or which replaces it.
     */
    public static function share(QueryBuilder $statement, QueryBuilder $embedded): self
    {
        $aliases = self::of($statement);
        \assert(self::$statements !== null);
        self::$statements[$embedded] = $aliases;

        return $aliases;
    }

    /**
     * `$name` with a numeric suffix no earlier call on this statement returned.
     */
    public function unique(string $name): string
    {
        if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(\sprintf('A DQL alias must start with a letter or an underscore and hold only letters, digits and underscores; "%s" does not.', $name));
        }

        $this->issued[$name] = ($this->issued[$name] ?? 0) + 1;

        return $name . '_' . $this->issued[$name];
    }
}
