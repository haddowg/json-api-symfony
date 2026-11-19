<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * The condition keeping the entity at an alias to the rows a type's query extensions
 * admit, as the related endpoint's read does: a membership `IN` over a subquery rooted
 * on the entity, with the extensions applied under {@see QueryPurpose::FetchRelatedCollection}.
 * A filter's `EXISTS` and a sort's join both read rows of another type through it.
 *
 * @internal
 */
final class ExtensionScope
{
    /**
     * The condition, with the extensions' parameters bound on `$bindOn`, the query that
     * executes it, or null when no extension scopes `$type`.
     *
     * @param ClassMetadata<object>            $metadata   the entity `$alias` names
     * @param list<DoctrineExtensionInterface> $extensions the extensions supporting `$type`
     */
    public static function condition(QueryBuilder $bindOn, string $alias, ClassMetadata $metadata, string $type, array $extensions, ?JsonApiRequestInterface $request): ?string
    {
        if ($extensions === []) {
            return null;
        }

        $scopeAlias = $alias . '_scope';
        $scope = $bindOn->getEntityManager()->createQueryBuilder()
            ->select(DoctrineIdentifiers::path($metadata, $scopeAlias))
            ->from($metadata->getName(), $scopeAlias);
        $context = new ExtensionContext($type, QueryPurpose::FetchRelatedCollection, $request);
        foreach ($extensions as $extension) {
            $scope = $extension->apply($scope, $context);
        }

        $parameters = $bindOn->getParameters();
        foreach ($scope->getParameters() as $parameter) {
            // An extension binds the same name on every hop it scopes; one binding serves them all.
            $bound = $bindOn->getParameter($parameter->getName());
            if ($bound !== null) {
                $parameters->removeElement($bound);
            }
            $parameters->add($parameter);
        }

        return (string) $bindOn->expr()->in(DoctrineIdentifiers::path($metadata, $alias), $scope->getDQL());
    }
}
