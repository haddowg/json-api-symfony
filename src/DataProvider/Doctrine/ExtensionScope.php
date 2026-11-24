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
        $admitted = self::admitted($bindOn, $alias . '_scope', $metadata, $type, $extensions, $request);

        return $admitted === null ? null : (string) $bindOn->expr()->in(DoctrineIdentifiers::path($metadata, $alias), $admitted);
    }

    /**
     * The DQL of a subquery selecting the ids of the entity `$metadata` names that a
     * type's query extensions admit, rooted at `$scopeAlias`, with the extensions'
     * parameters bound on `$bindOn`, or null when no extension scopes `$type`. A value
     * compared with it, such as a stored morph target id, keeps to the admitted rows.
     *
     * @param ClassMetadata<object>            $metadata
     * @param list<DoctrineExtensionInterface> $extensions the extensions supporting `$type`
     * @param string|null                      $function   a DQL function the selected id is read through
     */
    public static function admitted(QueryBuilder $bindOn, string $scopeAlias, ClassMetadata $metadata, string $type, array $extensions, ?JsonApiRequestInterface $request, ?string $function = null): ?string
    {
        if ($extensions === []) {
            return null;
        }

        $id = DoctrineIdentifiers::path($metadata, $scopeAlias);
        $scope = $bindOn->getEntityManager()->createQueryBuilder()
            ->select($function === null ? $id : \sprintf('%s(%s)', $function, $id))
            ->from($metadata->getName(), $scopeAlias);
        // The scope is embedded in `$bindOn`'s statement, so its extensions draw aliases from it.
        $context = new ExtensionContext($type, QueryPurpose::FetchRelatedCollection, $request, aliases: ExtensionAliases::share($bindOn, $scope));
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

        return $scope->getDQL();
    }
}
