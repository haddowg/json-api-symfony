<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;

/**
 * The shared `articles` declaration both functional kernels serve: one set of
 * fields, filters and pagination, so the in-memory and Doctrine providers are
 * exercised by **identical** spec assertions and a failure localizes to the
 * provider, not the fixture. The concrete subclasses only choose the data
 * layer ({@see ArticleResource} in-memory,
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineArticleResource}
 * Doctrine-mapped).
 *
 * `title` and `category` are sortable (`category` carries ties, so multi-field
 * sort composition is observable); `body` deliberately is not, so sorting on a
 * declared-but-unsortable field is testable. The filters cover the exact /
 * contains / id-set shapes of the Phase-1 vocabulary.
 *
 * `title` and `category` also carry validation constraints (required + length,
 * and an enum) that are inert on reads but exercised by the write/validation
 * conformance suites — the same declaration drives both halves.
 */
abstract class BaseArticleResource extends AbstractResource
{
    public static string $type = 'articles';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->sortable()->required()->minLength(3)->maxLength(50),
            Str::make('body'),
            Str::make('category')->sortable()->in(['guide', 'news', 'opinion']),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('title'),
            Where::make('titleContains', 'title', 'like'),
            WhereIdIn::make(),
        ];
    }

    public function pagination(): ?PaginatorInterface
    {
        return PagePaginator::make();
    }
}
