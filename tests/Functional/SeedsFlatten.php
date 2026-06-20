<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\FlattenAuthorEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\FlattenAuthorEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\FlattenBookEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\FlattenCountryEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\FlattenCountryEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\FlattenPublisherEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\FlattenPublisherEntityFactory;

/**
 * `afterBoot()` for the Doctrine flattened-attribute (`on()`) suite (bundle ADR
 * 0085): creates the in-memory SQLite schema and seeds the SAME graph the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\FlattenProviderFactory} seeds —
 *
 *  - authors 1 "Ada Lovelace", 2 "Grace Hopper", 3 "Edsger Dijkstra";
 *  - publisher 1 "Acme Press" (shared by every book — the eager publisher load
 *    batches three books to ONE round-trip);
 *  - books 1 "Algorithms" (author 1), 2 "Compilers" (author 2), 3 "Structured
 *    Programming" (author 3), each with a DISTINCT author so the flattened read is
 *    the per-row N+1 the budget witness collapses, all sharing publisher 1.
 *
 * Insertion order assigns the ids (no explicit `id`), so they coincide with the
 * in-memory seed. The unit of work is cleared so a subsequent `find()` returns a
 * fresh managed entity (the budget witness needs the `author` association LAZY).
 */
trait SeedsFlatten
{
    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // One shared country (the SECOND hop the book's multi-hop `on('author.country')`
        // walks to): every author points at it, so the multi-hop eager walk batches the
        // three authors to ONE country `WHERE id IN` load (the multi-hop budget witness).
        $country = FlattenCountryEntityFactory::createOne(['name' => 'Wonderland']);
        \assert($country instanceof FlattenCountryEntity);

        $authors = [];
        foreach (['Ada Lovelace', 'Grace Hopper', 'Edsger Dijkstra'] as $name) {
            $authors[] = FlattenAuthorEntityFactory::createOne(['name' => $name, 'country' => $country]);
        }

        $publisher = FlattenPublisherEntityFactory::createOne(['name' => 'Acme Press']);
        \assert($publisher instanceof FlattenPublisherEntity);

        // Each book's `editor` (the VISIBLE backing relation for the flattened
        // `editorName`) starts as the SAME author as its `author`, so a same-body
        // update that SWITCHES `editor` proves the flattened value lands on the NEW
        // editor and the previously associated editor is left untouched.
        $titles = ['Algorithms', 'Compilers', 'Structured Programming'];
        foreach ($titles as $index => $title) {
            $author = $authors[$index];
            \assert($author instanceof FlattenAuthorEntity);
            FlattenBookEntityFactory::createOne([
                'title' => $title,
                'author' => $author,
                'publisher' => $publisher,
                'editor' => $author,
            ]);
        }

        // Book 4 has NO author: the require-exists 422 write witness (a flattened
        // `authorName` write never auto-instantiates a missing related author).
        FlattenBookEntityFactory::createOne([
            'title' => 'Orphan',
            'author' => null,
            'publisher' => $publisher,
            'editor' => null,
        ]);

        $entityManager->flush();
        $entityManager->clear();
    }
}
