<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * A second JSON:API type (`unpaginatedArticles`) mapped to the SAME
 * {@see ArticleEntity} as `articles`, but with pagination **disabled**:
 * {@see pagination()} returns `null`, so the collection is fetched whole (G21 §5).
 *
 * It is the fetch-all witness for the query-budget suite: a whole-collection fetch
 * has its size in hand for free, so the handler renders `meta.total` unconditionally
 * with NO `COUNT` query and no `meta.page`. Only the read fields are declared (the
 * budget suite never writes it).
 */
#[AsJsonApiResource(entity: ArticleEntity::class)]
final class UnpaginatedArticleResource extends AbstractResource
{
    public static string $type = 'unpaginatedArticles';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('body'),
        ];
    }

    /**
     * Pagination is disabled (G21): the `null` return is the single source of truth,
     * so the collection is fetched whole and renders `meta.total` for free.
     */
    public function pagination(?PaginatorInterface $serverDefault): ?PaginatorInterface
    {
        return null;
    }
}
