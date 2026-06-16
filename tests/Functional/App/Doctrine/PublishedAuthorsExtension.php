<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;
use haddowg\JsonApiBundle\DataProvider\Doctrine\QueryPurpose;

/**
 * An application-style extension scoping `authors` to exclude `Ada Lovelace` — the
 * exact shape of a soft-delete / tenant / published-only base constraint a host
 * writes for the RELATED type of a many-to-many relation (the `editors` relation is
 * `articles → authors`). Per the {@see DoctrineExtensionInterface} contract it reads
 * the builder's root alias and constrains it there, so the bundle must hand it a
 * builder rooted on the AUTHOR entity even on the parent-rooted batched pair-shape
 * fetch (bundle ADR 0061) — otherwise the constraint lands on the parent (`articles`)
 * and silently fails or errors. Records each purpose it saw so the test can assert the
 * extension was actually invoked on the windowed include path.
 */
final class PublishedAuthorsExtension implements DoctrineExtensionInterface
{
    /**
     * @var list<QueryPurpose>
     */
    public array $applied = [];

    public function supports(string $type): bool
    {
        return $type === 'authors';
    }

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder
    {
        $this->applied[] = $context->purpose;

        $alias = $builder->getRootAliases()[0]
            ?? throw new \LogicException('The builder arrived without a root alias.');

        // Exclude one editor (author 1, Ada Lovelace) — a base constraint the client
        // cannot undo, the soft-delete/published-only twin scoped on the AUTHOR entity.
        return $builder
            ->andWhere(\sprintf('%s.name != :published_author', $alias))
            ->setParameter('published_author', 'Ada Lovelace');
    }
}
