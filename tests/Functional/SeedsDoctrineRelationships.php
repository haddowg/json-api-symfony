<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\ArticleFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ArticleEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ArticleEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\AuthorEntity;
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

        // No explicit ids anywhere: each entity's store-provided `AUTO` column
        // assigns a sequential int in insertion order. Authors, articles and
        // comments are each iterated in their canonical fixture order, so the
        // database assigns the per-type ids the fixtures document (1..N). The
        // relationship wiring references the *created objects* (which carry the
        // assigned id), never a pre-set literal, so it stays correct.
        $authors = [];
        foreach (ArticleFixtures::authors() as $id => $author) {
            $authors[(string) $id] = AuthorEntityFactory::createOne(['name' => $author['name']]);
        }

        $relationships = ArticleFixtures::relationships();

        $articles = [];
        foreach (ArticleFixtures::data() as $id => $article) {
            $id = (string) $id;
            $authorId = $relationships[$id]['author'] ?? null;

            $articles[$id] = ArticleEntityFactory::createOne([
                ...$article,
                'author' => $authorId !== null ? ($authors[$authorId] ?? null) : null,
            ]);
        }

        $featured = ArticleFixtures::featuredComments();

        foreach (ArticleFixtures::comments() as $id => $comment) {
            $articleId = self::articleOwningComment($relationships, (string) $id);
            $featuredArticleId = self::articleFeaturingComment($featured, (string) $id);

            CommentEntityFactory::createOne([
                'body' => $comment['body'],
                'article' => $articleId !== null ? ($articles[$articleId] ?? null) : null,
                'featuredArticle' => $featuredArticleId !== null ? ($articles[$featuredArticleId] ?? null) : null,
            ]);
        }

        // Link the unidirectional ManyToMany `editors` join rows. Foundry's
        // ArticleEntityFactory builds the entity through its constructor (which
        // does not take `editors`), so the membership is set by mutating each
        // managed article's collection and flushing once — guaranteeing the
        // `article_editors` rows are persisted before the unit of work is cleared.
        foreach (ArticleFixtures::editors() as $id => $editorIds) {
            $article = $articles[(string) $id] ?? null;
            if (!$article instanceof ArticleEntity) {
                continue;
            }

            foreach ($editorIds as $editorId) {
                $author = $authors[$editorId] ?? null;
                if ($author instanceof AuthorEntity) {
                    $article->editors->add($author);
                }
            }
        }
        $entityManager->flush();

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
