<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Query;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\QueryPurpose;

/**
 * The query-extension seam (seam 1): scopes every `albums` query the Doctrine
 * provider builds to `published = true` — a base constraint the client cannot
 * undo, the published-only twin of a tenant or soft-delete scope. Discovered by
 * plain autoconfiguration (the bundle tags any {@see DoctrineExtensionInterface}),
 * so it needs no service definition beyond `src/` registration.
 *
 * Re-themed from the bundle's own
 * {@see https://github.com/haddowg/json-api-symfony GuideOnlyArticlesExtension}
 * (a `category = 'guide'` scope) into the music domain.
 *
 * Per the {@see QueryPurpose} fail-closed contract it applies **unconditionally**
 * (no exhaustive `match` over the purpose — a new purpose must not silently drop
 * the scope), so the constraint holds on both the `GET /albums` collection (its
 * pre-window COUNT included) and the `GET /albums/{id}` single fetch: an
 * unpublished album is simply absent, which the handler renders as a `404`. A
 * client `filter[…]`/`sort` always ANDs on top because extensions run first. The
 * bound parameter name avoids the reserved `jsonapi_` prefix the bundle's own
 * handlers use, so it never collides.
 */
final class PublishedAlbumsExtension implements DoctrineExtensionInterface
{
    public function supports(string $type): bool
    {
        return $type === 'albums';
    }

    public function apply(QueryBuilder $builder, string $type, QueryPurpose $purpose): QueryBuilder
    {
        $alias = $builder->getRootAliases()[0]
            ?? throw new \LogicException('The builder arrived without a root alias.');

        return $builder
            ->andWhere(\sprintf('%s.published = :published_only', $alias))
            ->setParameter('published_only', true);
    }
}
