<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApiBundle\Serializer\Doctrine\DoctrineRelationshipLoadState;
use haddowg\JsonApiBundle\Tests\Functional\App\ArticleFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ArticleEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ArticleEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\AuthorEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CommentEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A focused functional unit test of the {@see DoctrineRelationshipLoadState}
 * predicate against a real, managed Doctrine entity over an in-memory SQLite
 * database — driving the seam directly so the load-state verdict is asserted
 * regardless of whether the full read pipeline activates compound `include`s.
 *
 * It proves the predicate's contract on the three cases that drive the policy:
 *  - a to-many over an **uninitialised** PersistentCollection reports NOT loaded
 *    (the omission case), and the check itself does not initialise the
 *    collection;
 *  - the **same** to-many, once initialised, reports loaded (the include-wins
 *    equivalent at the predicate level — when the linkage is in memory the data
 *    is emitted);
 *  - a to-one always reports loaded (a lazy proxy carries its identifier).
 *
 * The full-request omission is additionally witnessed end-to-end by
 * {@see DoctrineRelationshipReadTest::aLoadAwareToManyOverAnUninitialisedCollectionOmitsDataButKeepsLinks}.
 */
final class DoctrineRelationshipLoadStateTest extends JsonApiFunctionalTestCase
{
    private EntityManagerInterface $entityManager;

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // Store-provided ids: no explicit `id` is passed; the `AUTO` columns assign
        // sequential ints by insertion order against the freshly recreated schema,
        // so the first author is id 1, the article is id 1, and the comments are
        // ids 1, 2 — the canonical fixture ids.
        $firstAuthor = null;
        foreach (ArticleFixtures::authors() as $author) {
            $created = AuthorEntityFactory::createOne(['name' => $author['name']]);
            $firstAuthor ??= $created;
        }

        $article = ArticleEntityFactory::createOne([
            ...ArticleFixtures::data()['1'],
            'author' => $firstAuthor,
        ]);

        // Article 1 features comments 1, 2 in the separate `featuredComments`
        // association backing the load-aware `lazyComments` relation.
        foreach (['1', '2'] as $commentId) {
            CommentEntityFactory::createOne([
                'body' => ArticleFixtures::comments()[$commentId]['body'],
                'featuredArticle' => $article,
            ]);
        }

        // A subsequent find() then returns a managed entity whose to-many
        // associations are uninitialised PersistentCollections.
        $entityManager->clear();
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aToManyOverAnUninitialisedCollectionReportsNotLoadedWithoutInitialisingIt(): void
    {
        $predicate = new DoctrineRelationshipLoadState($this->entityManager);
        $relation = HasMany::make('lazyComments')->type('comments')->storedAs('featuredComments')->dataOnlyWhenLoaded();

        $article = $this->entityManager->find(ArticleEntity::class, '1');
        self::assertInstanceOf(ArticleEntity::class, $article);

        $collection = $article->featuredComments;
        self::assertInstanceOf(PersistentCollection::class, $collection);
        self::assertFalse($collection->isInitialized(), 'precondition: the collection must start uninitialised');

        self::assertFalse($predicate->isRelationshipLoaded($article, $relation));

        // The predicate must not have triggered a load while answering.
        self::assertFalse($collection->isInitialized(), 'the predicate must answer without initialising the collection');
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aToManyOverAnInitialisedCollectionReportsLoaded(): void
    {
        $predicate = new DoctrineRelationshipLoadState($this->entityManager);
        $relation = HasMany::make('lazyComments')->type('comments')->storedAs('featuredComments')->dataOnlyWhenLoaded();

        $article = $this->entityManager->find(ArticleEntity::class, '1');
        self::assertInstanceOf(ArticleEntity::class, $article);

        // Initialise the collection (the include-wins precondition: the linkage is
        // now in memory, so the predicate reports it loaded and data is emitted).
        $collection = $article->featuredComments;
        self::assertInstanceOf(PersistentCollection::class, $collection);
        $collection->initialize();
        self::assertTrue($collection->isInitialized());

        self::assertTrue($predicate->isRelationshipLoaded($article, $relation));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aToOneAlwaysReportsLoaded(): void
    {
        $predicate = new DoctrineRelationshipLoadState($this->entityManager);
        $relation = BelongsTo::make('lazyAuthor')->type('authors')->storedAs('author')->dataOnlyWhenLoaded();

        $article = $this->entityManager->find(ArticleEntity::class, '1');
        self::assertInstanceOf(ArticleEntity::class, $article);

        // A to-one is always loaded — a lazy ManyToOne proxy carries its id, so
        // emitting the resource identifier needs no database round-trip.
        self::assertTrue($predicate->isRelationshipLoaded($article, $relation));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelationWhoseColumnIsNotADoctrineToManyReportsLoaded(): void
    {
        $predicate = new DoctrineRelationshipLoadState($this->entityManager);
        // `column()` names a property that is not a collection-valued association
        // on the entity: the predicate must not change behaviour for a relation it
        // cannot reason about — it reports loaded.
        $relation = HasMany::make('unmapped')->type('comments')->storedAs('notAnAssociation')->dataOnlyWhenLoaded();

        $article = $this->entityManager->find(ArticleEntity::class, '1');
        self::assertInstanceOf(ArticleEntity::class, $article);

        self::assertTrue($predicate->isRelationshipLoaded($article, $relation));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aNonEntityModelReportsLoaded(): void
    {
        $predicate = new DoctrineRelationshipLoadState($this->entityManager);
        $relation = HasMany::make('lazyComments')->type('comments')->storedAs('featuredComments')->dataOnlyWhenLoaded();

        // A model the entity manager does not manage: the predicate must not touch
        // it — it reports loaded.
        self::assertTrue($predicate->isRelationshipLoaded(new \stdClass(), $relation));
    }
}
