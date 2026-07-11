<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\AbstractRelationBuilder;
use haddowg\JsonApi\Resource\Field\Accessor;

/**
 * The shared `articles` declaration for the identifier-meta conformance suite
 * (core ADR 0084): the {@see BaseArticleResource} vocabulary with a **parent-aware
 * `identifierMeta()`** declared on the to-one `author` and the to-many `comments`.
 *
 * Each resolver reads both the owning article and the related object, so the meta
 * it stamps on every linkage identifier is something the related resource's own
 * `getMeta()` could never produce — `fromArticle` is the *parent's* id carried on
 * the *related* identifier. The same closures run identically on both providers
 * (the public `id` / `name` members are read through core's {@see Accessor}), so a
 * failure localises to the provider, not the fixture.
 *
 * Not `final` so the Doctrine variant
 * ({@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineIdentifierMetaArticleResource})
 * inherits the same declarations.
 */
class IdentifierMetaArticleResource extends BaseArticleResource
{
    public function fields(): array
    {
        $fields = parent::fields();
        foreach ($fields as $field) {
            if (!$field instanceof AbstractRelationBuilder) {
                continue;
            }

            if ($field->name() === 'author') {
                $field->identifierMeta(static fn(mixed $article, mixed $author, JsonApiRequestInterface $request): array => [
                    'fromArticle' => Accessor::get($article, 'id'),
                    'authorName' => Accessor::get($author, 'name'),
                ]);
            }

            if ($field->name() === 'comments') {
                $field->identifierMeta(static fn(mixed $article, mixed $comment, JsonApiRequestInterface $request): array => [
                    'fromArticle' => Accessor::get($article, 'id'),
                    'commentId' => Accessor::get($comment, 'id'),
                ]);
            }
        }

        return $fields;
    }
}
