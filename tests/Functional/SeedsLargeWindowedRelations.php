<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ArticleEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\AuthorEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CommentEntity;

/**
 * Seeds a graph with a LARGE windowed relation (article 1 with 50 comments) plus the
 * m2m `editors` and a tie scenario, for the bounded ROW_NUMBER windowed-include batch
 * conformance (bundle ADR 0065): the Doctrine schema is created in-memory and the same
 * deterministic content is seeded that {@see WindowedIncludeBatchConformanceTestCase}'s
 * in-memory witness mirrors, so both providers assert identical documents.
 *
 * Article 1: 50 comments, bodies `comment-00` .. `comment-49` in PK order, so sorting
 * descending by body selects the LAST 5 (`comment-49` .. `comment-45`) onto page 1 — the
 * bounded-fetch and true-total assertions. It also has both editors (the m2m shape).
 * Article 2: 3 comments. Article 3: two comments that TIE on body (`tie`) with PKs in a
 * known order, for the tie-determinism assertion.
 */
trait SeedsLargeWindowedRelations
{
    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // Authors 1 (Ada) and 2 (Grace), the m2m editors of article 1.
        $ada = new AuthorEntity(null, 'Ada Lovelace');
        $grace = new AuthorEntity(null, 'Grace Hopper');
        $entityManager->persist($ada);
        $entityManager->persist($grace);

        $article1 = new ArticleEntity(null, 'Article one');
        $article2 = new ArticleEntity(null, 'Article two');
        $article3 = new ArticleEntity(null, 'Article three');
        $entityManager->persist($article1);
        $entityManager->persist($article2);
        $entityManager->persist($article3);
        $entityManager->flush();

        // Article 1: 50 comments in PK order, bodies comment-00 .. comment-49. Each is
        // PINNED by article 1 (the `pinnedArticle` owning side backing the COUNTABLE
        // inverse-FK `pinnedComments` relation, the unique-column shape) AND featured by
        // it (the `featuredArticle` owning side backing the NON-countable `lazyComments`
        // relation), so both inverse-FK assertions window the same 50-member set.
        foreach (WindowedSeedData::largeBodies() as $body) {
            $entityManager->persist(new CommentEntity(null, $body, $article1, featuredArticle: $article1, pinnedArticle: $article1));
        }

        // Article 2: 3 comments pinned by article 2.
        foreach (WindowedSeedData::smallBodies() as $body) {
            $entityManager->persist(new CommentEntity(null, $body, $article2, pinnedArticle: $article2));
        }

        // Article 3: two comments tied on body (pinned by article 3), PKs ascending.
        foreach (WindowedSeedData::tieBodies() as $body) {
            $entityManager->persist(new CommentEntity(null, $body, $article3, pinnedArticle: $article3));
        }

        // The m2m editors of article 1 (both authors), article 2 (Ada only).
        $article1->editors->add($ada);
        $article1->editors->add($grace);
        $article2->editors->add($ada);

        $entityManager->flush();
        $entityManager->clear();
    }
}
