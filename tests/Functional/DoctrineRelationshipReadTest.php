<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\ArticleFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ArticleEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\AuthorEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CommentEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;

/**
 * {@see RelationshipReadConformanceTestCase} against the Doctrine provider: the
 * same relationship-linkage assertions as the in-memory suite, executed as real
 * DQL over an in-memory SQLite database created per test and seeded — with the
 * `ManyToOne` author and `OneToMany` comment associations wired — through the
 * Foundry factories from the shared {@see ArticleFixtures}.
 */
final class DoctrineRelationshipReadTest extends RelationshipReadConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        // The in-memory SQLite database is empty per connection: create the
        // schema, then seed authors → articles → comments so the foreign keys
        // resolve against already-persisted rows.
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

        foreach (ArticleFixtures::comments() as $id => $comment) {
            $articleId = self::articleOwningComment($relationships, (string) $id);

            CommentEntityFactory::createOne([
                'id' => (string) $id,
                'body' => $comment['body'],
                'article' => $articleId !== null ? ($articles[$articleId] ?? null) : null,
            ]);
        }

        $entityManager->clear();
    }

    /**
     * Finds the article id that owns the given comment in the linkage map.
     *
     * @param array<int|string, array{author: string, comments: list<string>}> $relationships
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
