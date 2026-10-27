<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionLinks;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Link\ActionLink;
use haddowg\JsonApi\Resource\Link\DeclaredLink;
use haddowg\JsonApiBundle\Tests\Functional\App\Article;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ArticleEntity;

/**
 * The action links both article resources declare, picked by the kernel environment.
 *
 * - `review` has no `asLink`, so the declaration alone links it, while the article is a
 *   guide;
 * - `feature` is gated to admins and links only guides;
 * - `archive` sets `asLink`, and its declaration narrows it to news articles.
 *
 * The `missing`, `clash` and `duplicate` environments each misdeclare one link.
 */
trait LinksArticleActions
{
    public function __construct(private readonly string $variant = 'test')
    {
        parent::__construct();
    }

    public function links(): array
    {
        return match ($this->variant) {
            'missing' => [ActionLink::for('retract')],
            'clash' => [
                ActionLink::for('review'),
                DeclaredLink::make('review')->hrefUsing(static fn(mixed $article, JsonApiRequestInterface $request): ?string => null),
            ],
            'duplicate' => [ActionLink::for('review'), ActionLink::for('review')],
            default => [
                ActionLink::for('review')
                    ->when(static fn(Article|ArticleEntity $article, JsonApiRequestInterface $request): bool => $article->category === 'guide')
                    ->describedAs('Present while the article is a guide.'),
                ActionLink::for('feature')
                    ->when(static fn(Article|ArticleEntity $article, JsonApiRequestInterface $request): bool => $article->category === 'guide'),
                ActionLink::for('archive')
                    ->when(static fn(Article|ArticleEntity $article, JsonApiRequestInterface $request): bool => $article->category === 'news')
                    ->describedAs('Present while the article is news.'),
            ],
        };
    }
}
