<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * The related type's query extensions, applied to a batched related read the way the
 * related endpoint applies them: on the related entity under
 * {@see QueryPurpose::FetchRelatedCollection}, and again under
 * {@see QueryPurpose::IncludeHydration} on the query that loads the related rows by id.
 *
 * @internal
 */
final readonly class RelatedExtensions
{
    /**
     * @param list<DoctrineExtensionInterface> $extensions the extensions that support `$relatedType`
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private array $extensions,
        private string $relatedType,
        private ?JsonApiRequestInterface $request,
    ) {}

    public function isEmpty(): bool
    {
        return $this->extensions === [];
    }

    /**
     * Narrows `$builder`'s related entity, reached under `$relatedAlias`. When the query
     * roots on the related entity the extensions apply to it directly. Otherwise
     * (`$pairRelatedClass` names the related class of a query rooted on the parent) they
     * apply to a related-rooted subquery, and the related ids are kept to its rows.
     *
     * @param class-string|null $pairRelatedClass
     */
    public function scope(QueryBuilder $builder, string $relatedAlias, ?string $pairRelatedClass = null): QueryBuilder
    {
        if ($this->extensions === []) {
            return $builder;
        }

        if ($pairRelatedClass === null) {
            $context = new ExtensionContext($this->relatedType, QueryPurpose::FetchRelatedCollection, $this->request, aliases: ExtensionAliases::of($builder));
            foreach ($this->extensions as $extension) {
                $builder = $extension->apply($builder, $context);
            }

            return $builder;
        }

        $subAlias = 'jsonapi_ext_related';
        $relatedMetadata = $this->entityManager->getClassMetadata($pairRelatedClass);
        $sub = $this->entityManager
            ->getRepository($pairRelatedClass)
            ->createQueryBuilder($subAlias)
            ->select(DoctrineIdentifiers::path($relatedMetadata, $subAlias));

        $context = new ExtensionContext($this->relatedType, QueryPurpose::FetchRelatedCollection, $this->request, aliases: ExtensionAliases::share($builder, $sub));
        foreach ($this->extensions as $extension) {
            $sub = $extension->apply($sub, $context);
        }

        // The extensions bound their parameters on the subquery, but only the outer
        // builder executes the merged DQL, so they move onto it with their declared types.
        $merged = $builder->getParameters();
        foreach ($sub->getParameters() as $parameter) {
            $merged->add($parameter);
        }
        $builder->setParameters($merged);

        return $builder->andWhere($builder->expr()->in(
            DoctrineIdentifiers::path($relatedMetadata, $relatedAlias),
            $sub->getDQL(),
        ));
    }

    /**
     * Applies the extensions to the query that loads the related rows by id.
     */
    public function hydrate(QueryBuilder $loader): QueryBuilder
    {
        $context = new ExtensionContext($this->relatedType, QueryPurpose::IncludeHydration, $this->request, aliases: ExtensionAliases::of($loader));
        foreach ($this->extensions as $extension) {
            $loader = $extension->apply($loader, $context);
        }

        return $loader;
    }
}
