<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Serializer;

use haddowg\JsonApi\Resource\DeclaresSingletonTypesInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\Serializer\PivotLinkageParentSerializer;
use haddowg\JsonApiBundle\Serializer\PivotMetaSerializer;
use haddowg\JsonApiBundle\Tests\Functional\App\Singleton\InMemoryInboxResource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The pivot decorators link the resource they wrap where it is routed: under its URI
 * segment, and without an id when it is a singleton.
 */
final class PivotDecoratorLinksTest extends TestCase
{
    #[Test]
    public function theParentDecoratorKeepsTheUriTypeAndResolver(): void
    {
        $server = Server::make()->register(InMemoryInboxResource::class);
        $decorator = new PivotLinkageParentSerializer($server->serializerFor('inboxes'), $server, [], []);

        self::assertSame('inbox', $decorator->uriType());
        $resolver = $decorator->serializerResolver();
        self::assertInstanceOf(DeclaresSingletonTypesInterface::class, $resolver);
        self::assertTrue($resolver->isSingleton('inboxes'));
    }

    #[Test]
    public function theMemberDecoratorKeepsTheUriTypeAndResolver(): void
    {
        $server = Server::make()->register(InMemoryInboxResource::class);
        $decorator = new PivotMetaSerializer($server->serializerFor('inboxes'), []);

        self::assertSame('inbox', $decorator->uriType());
        $resolver = $decorator->serializerResolver();
        self::assertInstanceOf(DeclaresSingletonTypesInterface::class, $resolver);
        self::assertTrue($resolver->isSingleton('inboxes'));
    }
}
