<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataProvider\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider;
use haddowg\JsonApiBundle\Server\IdEncoderResolver;
use haddowg\JsonApiBundle\Server\ResourceLocator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

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
        $relation = MorphToMany::make('items', ['notes', 'images'])->build();

        // Stubs, not mocks: the guard throws before touching either collaborator,
        // so there is nothing to configure or verify (and a mock without
        // expectations is what PHPUnit advises a stub for). The id-encoder resolver
        // is a real instance over an empty resource locator — it is never consulted
        // because the guard fires first.
        $provider = new DoctrineDataProvider(
            $this->createStub(EntityManagerInterface::class),
            [],
            new IdEncoderResolver(new ResourceLocator($this->emptyContainer(), [])),
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

    #[Test]
    public function itThrowsForCountingAPolymorphicToManyRelationship(): void
    {
        // The same boundary on the count seam (bundle ADR 0052): a polymorphic
        // to-many's members span entity classes, so they cannot be one grouped
        // COUNT — the guard fires before any EntityManager use, so a host supplies a
        // custom provider to count across types.
        $relation = MorphToMany::make('items', ['notes', 'images'])->build();

        $provider = new DoctrineDataProvider(
            $this->createStub(EntityManagerInterface::class),
            [],
            new IdEncoderResolver(new ResourceLocator($this->emptyContainer(), [])),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('items');

        $provider->countRelated(
            'libraries',
            [new \stdClass()],
            $relation,
            new CollectionCriteria(new QueryParameters([], [], [], [], [])),
            $this->createStub(JsonApiRequestInterface::class),
        );
    }

    private function emptyContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \LogicException(\sprintf('No service "%s" registered.', $id));
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }
}
