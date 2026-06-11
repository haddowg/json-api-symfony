<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\ArticleFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ArticleEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\AuthorEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CommentEntityFactory;

/**
 * Shared `afterBoot()` for the Doctrine relationship suites: creates the
 * in-memory SQLite schema and seeds authors → articles → comments — with the
 * `ManyToOne` author and the two `OneToMany` comment associations wired — from
 * the canonical {@see ArticleFixtures}, then clears the unit of work so a
 * subsequent `find()` returns a fresh managed entity (the precondition for the
 * load-state predicate's omission case).
 */
trait SeedsDoctrineRelationships
{
    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $authors = [];
        foreach (ArticleFixtures::authors() as $id => $author) {
            $authors[(string) $id] = AuthorEntityFactory::createOne(['id' => (string) $id, 'name' => $author['name']]);
        }

        $relationships = ArticleFixtures::relationships();

        $articles = [];
        foreach (ArticleFixtures::data() as $id => $article) {
            $id = (string) $id;
            $authorId = $relationships[$id]['author'] ?? null;

            $articles[$id] = ArticleEntityFactory::createOne([
                'id' => $id,
                ...$article,
                'author' => $authorId !== null ? ($authors[$authorId] ?? null) : null,
            ]);
        }

        $featured = ArticleFixtures::featuredComments();

        foreach (ArticleFixtures::comments() as $id => $comment) {
            $articleId = self::articleOwningComment($relationships, (string) $id);
            $featuredArticleId = self::articleFeaturingComment($featured, (string) $id);

            CommentEntityFactory::createOne([
                'id' => (string) $id,
                'body' => $comment['body'],
                'article' => $articleId !== null ? ($articles[$articleId] ?? null) : null,
                'featuredArticle' => $featuredArticleId !== null ? ($articles[$featuredArticleId] ?? null) : null,
            ]);
        }

        $entityManager->clear();
    }

    /**
     * Finds the article id that owns the given comment in the linkage map.
     *
     * @param array<int|string, array{author: ?string, comments: list<string>}> $relationships
     */
    private static function articleOwningComment(array $relationships, string $commentId): ?string
    {
        foreach ($relationships as $articleId => $links) {
            if (\in_array($commentId, $links['comments'], true)) {
                return (string) $articleId;
            }
        }

        return null;
    }

    /**
     * Finds the article id that *features* the given comment.
     *
     * @param array<int|string, list<string>> $featured
     */
    private static function articleFeaturingComment(array $featured, string $commentId): ?string
    {
        foreach ($featured as $articleId => $commentIds) {
            if (\in_array($commentId, $commentIds, true)) {
                return (string) $articleId;
            }
        }

        return null;
    }
}
