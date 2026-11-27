<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\NestedFieldset;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\SerializerResolverAwareInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\CountableControlsInterface;
use haddowg\JsonApi\Serializer\CountableSelfInterface;
use haddowg\JsonApi\Serializer\DeclaresEagerLoadsInterface;
use haddowg\JsonApi\Serializer\DeclaresFieldNamesInterface;
use haddowg\JsonApi\Serializer\DeclaresRelationsInterface;
use haddowg\JsonApi\Serializer\IncludeControlsInterface;
use haddowg\JsonApi\Serializer\RendersDeclaredLinksInterface;
use haddowg\JsonApi\Serializer\SelfLinkAwareInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Serializer\SerializesNestedFieldsetsInterface;
use haddowg\JsonApi\Serializer\UriTypeAwareInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\Serializer\PivotLinkageParentSerializer;
use haddowg\JsonApiBundle\Serializer\PivotMetaSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each pivot decorator forwards every optional serializer interface to the serializer it
 * wraps, so a decorated resource keeps its self-link toggle, URI type, counts, declared
 * fields and relations, eager loads, include controls, nested fieldsets and the resolver
 * its links are contributed through.
 */
final class PivotDecoratorForwardingTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(SerializerInterface): SerializerInterface}>
     */
    public static function decorators(): iterable
    {
        yield 'member' => [static fn(SerializerInterface $inner): SerializerInterface => new PivotMetaSerializer($inner, [])];
        yield 'parent' => [static fn(SerializerInterface $inner): SerializerInterface => new PivotLinkageParentSerializer($inner, Server::make(), [], [])];
    }

    /**
     * @param \Closure(SerializerInterface): SerializerInterface $decorate
     */
    #[Test]
    #[DataProvider('decorators')]
    public function itForwardsEveryOptionalInterface(\Closure $decorate): void
    {
        $resolver = Server::make();
        $inner = new ForwardingProbeSerializer($resolver);
        $decorator = $decorate($inner);
        $request = $this->createStub(JsonApiRequestInterface::class);

        self::assertInstanceOf(SelfLinkAwareInterface::class, $decorator);
        self::assertFalse($decorator->emitsSelfLink());
        self::assertInstanceOf(UriTypeAwareInterface::class, $decorator);
        self::assertSame('probe-uri', $decorator->uriType());
        self::assertInstanceOf(SerializerResolverAwareInterface::class, $decorator);
        self::assertSame($resolver, $decorator->serializerResolver());
        self::assertInstanceOf(CountableControlsInterface::class, $decorator);
        self::assertSame(['counted'], $decorator->getCountableRelationships(new \stdClass()));
        self::assertInstanceOf(CountableSelfInterface::class, $decorator);
        self::assertTrue($decorator->isCountable());
        self::assertInstanceOf(DeclaresFieldNamesInterface::class, $decorator);
        self::assertSame(['title'], $decorator->declaredFieldNames());
        self::assertInstanceOf(DeclaresEagerLoadsInterface::class, $decorator);
        self::assertSame(['author'], $decorator->eagerLoadRelationshipPaths());
        self::assertInstanceOf(DeclaresRelationsInterface::class, $decorator);
        self::assertNull($decorator->relationNamedIncludingHidden('missing'));
        self::assertInstanceOf(IncludeControlsInterface::class, $decorator);
        self::assertSame(3, $decorator->maxIncludeDepth());
        self::assertSame(['author'], $decorator->getAllowedIncludePaths());
        self::assertSame(['secret'], $decorator->getExcludedIncludePaths());
        self::assertSame(['hidden'], $decorator->getNonIncludableRelationships($request, new \stdClass()));
        self::assertInstanceOf(RendersDeclaredLinksInterface::class, $decorator);
        self::assertSame([], $decorator->renderDeclaredLinks(new \stdClass(), $request));
        self::assertInstanceOf(SerializesNestedFieldsetsInterface::class, $decorator);
        self::assertSame('selected', $decorator->getNestedFieldsetAttribute(new \stdClass(), $request, 'address', new NestedFieldset([])));
    }
}

/**
 * A serializer answering every optional interface with a value the test can recognise.
 */
final class ForwardingProbeSerializer implements
    SerializerInterface,
    SelfLinkAwareInterface,
    UriTypeAwareInterface,
    SerializerResolverAwareInterface,
    CountableControlsInterface,
    CountableSelfInterface,
    DeclaresFieldNamesInterface,
    DeclaresEagerLoadsInterface,
    DeclaresRelationsInterface,
    IncludeControlsInterface,
    RendersDeclaredLinksInterface,
    SerializesNestedFieldsetsInterface
{
    public function __construct(private readonly SerializerResolverInterface $resolver) {}

    public function getType(mixed $object): string
    {
        return 'probes';
    }

    public function getId(mixed $object): string
    {
        return '1';
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function emitsSelfLink(): bool
    {
        return false;
    }

    public function uriType(): string
    {
        return 'probe-uri';
    }

    public function setSerializerResolver(SerializerResolverInterface $resolver): void {}

    public function serializerResolver(): SerializerResolverInterface
    {
        return $this->resolver;
    }

    public function getCountableRelationships(mixed $object): array
    {
        return ['counted'];
    }

    public function isCountable(): bool
    {
        return true;
    }

    public function declaredFieldNames(): array
    {
        return ['title'];
    }

    public function eagerLoadRelationshipPaths(): array
    {
        return ['author'];
    }

    public function relationNamedIncludingHidden(string $name): ?RelationInterface
    {
        return null;
    }

    public function getNonIncludableRelationships(JsonApiRequestInterface $request, mixed $object): array
    {
        return ['hidden'];
    }

    public function maxIncludeDepth(): int
    {
        return 3;
    }

    public function getAllowedIncludePaths(): array
    {
        return ['author'];
    }

    public function getExcludedIncludePaths(): array
    {
        return ['secret'];
    }

    public function renderDeclaredLinks(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getNestedFieldsetAttribute(mixed $object, JsonApiRequestInterface $request, string $name, NestedFieldset $fieldset): mixed
    {
        return 'selected';
    }
}
