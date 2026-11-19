<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\Query\QueryException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The query extensions the Doctrine provider applied during the current request, so a
 * query Doctrine refuses for defining one alias twice can name the extensions that
 * built it.
 *
 * @internal
 */
final class AppliedExtensions implements ResetInterface
{
    /**
     * @var array<class-string, true>
     */
    private array $classes = [];

    /**
     * @param list<DoctrineExtensionInterface> $extensions
     */
    public function record(array $extensions): void
    {
        foreach ($extensions as $extension) {
            $this->classes[$extension::class] = true;
        }
    }

    /**
     * A {@see \LogicException} explaining `$throwable` when it is Doctrine refusing an alias
     * defined twice in a query built with extensions, else null.
     */
    public function explain(\Throwable $throwable): ?\LogicException
    {
        if ($this->classes === []) {
            return null;
        }

        for ($cause = $throwable; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof QueryException && \preg_match("/'([^']+)' is already defined\\./", $cause->getMessage(), $match) === 1) {
                return new \LogicException(\sprintf(
                    'Doctrine refused a query because the alias "%s" is defined twice in it. The query was built with the query extensions %s. An extension can run several times inside one query: on the type queried and again in the subquery that scopes each relation a filter, sort or include reaches through. Take every alias an extension adds from ExtensionContext::uniqueAlias() instead of a fixed name.',
                    $match[1],
                    \implode(', ', \array_keys($this->classes)),
                ), 0, $throwable);
            }
        }

        return null;
    }

    public function reset(): void
    {
        $this->classes = [];
    }
}
