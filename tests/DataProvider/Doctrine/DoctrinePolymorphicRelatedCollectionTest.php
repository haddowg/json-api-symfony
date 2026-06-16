<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataProvider\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Doctrine provider's polymorphic-to-many boundary: a `MorphToMany`'s members
 * span entity classes, so they cannot be one scoped query (the same boundary as
 * the many-to-many subquery scope). The provider throws "unsupported" before
 * touching the EntityManager — a host that needs it supplies a custom provider
 * that resolves the related members across types (ADR 0032).
 */
final class DoctrinePolymorphicRelatedCollectionTest extends TestCase
{
    #[Test]
    public function itThrowsForAPolymorphicToManyRelatedCollection(): void
    {
        // A real MorphToMany over two types: its relatedTypes() is ['notes','images'],
        // so the guard fires before any EntityManager use.
        $relation = MorphToMany::make('items')->types('notes', 'images');

        // Stubs, not mocks: the guard throws before touching either collaborator,
        // so there is nothing to configure or verify (and a mock without
        // expectations is what PHPUnit advises a stub for).
        $provider = new DoctrineDataProvider(
            $this->createStub(EntityManagerInterface::class),
            [],
        );

        $criteria = new CollectionCriteria(new QueryParameters([], [], [], [], []));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('items');

        $provider->fetchRelatedCollection(
            'notes',
            new \stdClass(),
            $relation,
            $criteria,
            $this->createStub(JsonApiRequestInterface::class),
        );
    }
}
