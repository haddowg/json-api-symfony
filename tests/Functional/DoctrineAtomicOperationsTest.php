<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\ArticleFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ArticleEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\AtomicDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\AuthorEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CommentEntityFactory;

/**
 * {@see AtomicOperationsConformanceTestCase} against the Doctrine persister: the same
 * batch assertions, executed as real `beginTransaction`/`flush`/`commit` (or
 * `rollback`) over an in-memory SQLite database seeded per test with the
 * author/comment associations wired — so a rolled-back batch leaves the rows
 * unchanged and a committed batch's foreign keys are actually written.
 */
final class DoctrineAtomicOperationsTest extends AtomicOperationsConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return AtomicDoctrineTestKernel::class;
    }

    /**
     * Creates and seeds the schema (authors → articles → comments, with the author
     * ManyToOne and comment OneToMany wired) — the same seed
     * {@see SeedsDoctrineRelationships} uses, inlined here because the atomic kernel
     * maps only the article/author/comment trio.
     */
    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

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

        foreach (ArticleFixtures::comments() as $id => $comment) {
            $articleId = self::articleOwningComment($relationships, (string) $id);

            CommentEntityFactory::createOne([
                'body' => $comment['body'],
                'article' => $articleId !== null ? ($articles[$articleId] ?? null) : null,
            ]);
        }

        $entityManager->clear();
    }

    /**
     * The article id that owns the given comment in the linkage map.
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
}
