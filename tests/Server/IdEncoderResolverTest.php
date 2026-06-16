<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Server;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Server\IdEncoderResolver;
use haddowg\JsonApiBundle\Server\ResourceLocator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Characterizes the {@see IdEncoderResolver} (bundle ADR 0038): it resolves a JSON:API
 * type to its resource through the global {@see ResourceLocator} (matching the static
 * `$type` without instantiating, then resolving the one instance) and reads that
 * resource's {@see Id} field encoder + route pattern — yielding `null` for a type with
 * no resource, no {@see Id} field, or no encoder/pattern (wire == storage, today's
 * behaviour).
 */
#[Group('spec:crud')]
final class IdEncoderResolverTest extends TestCase
{
    #[Test]
    public function itResolvesTheEncoderAndRoutePatternOfAnEncodedType(): void
    {
        $resolver = $this->resolverFor(new EncodedTypeResource());

        self::assertInstanceOf(IdEncoderInterface::class, $resolver->encoderFor('encoded'));
        self::assertSame('enc-[0-9a-f]+', $resolver->routePatternFor('encoded'));
    }

    #[Test]
    public function theResolvedEncoderRoundTripsThroughTheField(): void
    {
        $encoder = $this->resolverFor(new EncodedTypeResource())->encoderFor('encoded');
        self::assertInstanceOf(IdEncoderInterface::class, $encoder);

        $wire = $encoder->encode('7');
        self::assertNotSame('7', $wire);
        self::assertSame('7', $encoder->decode($wire));
        self::assertNull($encoder->decode('not-a-token'));
    }

    #[Test]
    public function aTypeWithNoEncoderYieldsNull(): void
    {
        // A plain resource: no encoder, no route pattern — wire == storage.
        $resolver = $this->resolverFor(new PlainTypeResource());

        self::assertNull($resolver->encoderFor('plain'));
        self::assertNull($resolver->routePatternFor('plain'));
    }

    #[Test]
    public function anUnregisteredTypeYieldsNull(): void
    {
        $resolver = $this->resolverFor(new PlainTypeResource());

        self::assertNull($resolver->encoderFor('nonexistent'));
        self::assertNull($resolver->routePatternFor('nonexistent'));
    }

    private function resolverFor(AbstractResource ...$resources): IdEncoderResolver
    {
        $byClass = [];
        $classes = [];
        foreach ($resources as $resource) {
            $byClass[$resource::class] = $resource;
            $classes[] = $resource::class;
        }

        $container = new class ($byClass) implements ContainerInterface {
            /**
             * @param array<class-string, AbstractResource> $byClass
             */
            public function __construct(private readonly array $byClass) {}

            public function get(string $id): mixed
            {
                return $this->byClass[$id] ?? throw new \LogicException(\sprintf('No service "%s".', $id));
            }

            public function has(string $id): bool
            {
                return isset($this->byClass[$id]);
            }
        };

        return new IdEncoderResolver(new ResourceLocator($container, $classes));
    }
}

/**
 * A resource whose id is the wire form of a distinct storage key, with a route
 * pattern from {@see Id::matchAs()}.
 */
final class EncodedTypeResource extends AbstractResource
{
    public static string $type = 'encoded';

    public function fields(): array
    {
        return [
            Id::make()->encodeUsing(new PrefixHexEncoder())->matchAs('enc-[0-9a-f]+'),
            Str::make('name'),
        ];
    }
}

/**
 * A plain resource: no encoder, no route pattern (wire == storage).
 */
final class PlainTypeResource extends AbstractResource
{
    public static string $type = 'plain';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}

/**
 * A trivial reversible hex codec for the resolver characterization.
 */
final class PrefixHexEncoder implements IdEncoderInterface
{
    public function encode(mixed $storageKey): string
    {
        $key = \is_scalar($storageKey) ? (string) $storageKey : '';

        return 'enc-' . \bin2hex($key);
    }

    public function decode(string $wireId): mixed
    {
        if (!\str_starts_with($wireId, 'enc-')) {
            return null;
        }

        $bytes = \hex2bin(\substr($wireId, 4));

        return $bytes === false ? null : $bytes;
    }
}
