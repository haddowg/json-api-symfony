<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;

/**
 * The shared `comments` declaration both functional kernels serve — the related
 * type an article's to-many `comments` relationship links to. Minimal: an id
 * and a single `body` attribute. Registering it makes the type known to the
 * serializer resolver, so {@see \haddowg\JsonApi\Resource\Field\HasMany} can
 * emit a list of `{type: 'comments', id: …}` identifiers.
 */
abstract class BaseCommentResource extends AbstractResource
{
    public static string $type = 'comments';

    public function fields(): array
    {
        return [
            // Store-provided id: a database auto-increment assigns it (core ADR 0048).
            Id::make(),
            Str::make('body')->sortable(),
        ];
    }

    /**
     * The related-collection vocabulary (Phase 4 P7): this is what scopes
     * `GET /articles/{id}/comments?filter[body]=…&sort=…` — the comment filters
     * and sorts the related to-many endpoint resolves against, not the article's.
     */
    public function filters(): array
    {
        return [
            Where::make('body'),
        ];
    }
}
