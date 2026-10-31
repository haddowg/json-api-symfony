<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource;
use haddowg\JsonApiBundle\OpenApi\Metadata\TypeMetadata;
use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\ResourcesOnServerPass;
use PHPUnit\Framework\Attributes\Test;

/**
 * What each server reads from its own classes (bundle ADR 0034). The
 * resource-less `notes` type has one relations provider per server: the default
 * server's `author` only an admin may read, the named server's `author` is ungated and
 * it adds an `editor`. `segmentWidgets` is served by a different class on each server:
 * the default server's declares the `alpha-widgets` URI segment, a description, a
 * singular and an `x-server` extension, the named server's declares none of them. An
 * action on it is served on both. `labels` has a standalone serializer per server, each
 * rendering its own `source`. Note 1's author and editor are note 2.
 *
 * @phpstan-require-extends JsonApiFunctionalTestCase
 */
trait ServerKeyedRegistriesConformance
{
    #[Test]
    public function eachServerServesItsOwnStandaloneRelations(): void
    {
        self::assertSame(200, $this->handle('/v5/notes/1/relationships/editor')->getStatusCode());
        self::assertSame(404, $this->handle('/notes/1/relationships/editor')->getStatusCode());
    }

    #[Test]
    public function eachServerServesItsOwnStandaloneSerializer(): void
    {
        self::assertSame('default', $this->attribute('/labels/1', 'source'));
        self::assertSame('v5', $this->attribute('/v5/labels/1', 'source'));
    }

    #[Test]
    public function eachServerEnforcesItsOwnStandaloneRelationSecurity(): void
    {
        self::assertSame(401, $this->handle('/notes/1/author')->getStatusCode());
        self::assertSame(200, $this->handle('/notes/1/author', 'GET', null, ['HTTP_AUTHORIZATION' => 'Bearer admin'])->getStatusCode());

        $named = $this->handle('/v5/notes/1/author');
        self::assertSame(200, $named->getStatusCode(), (string) $named->getContent());
    }

    #[Test]
    public function anActionIsServedUnderEachServersOwnUriSegment(): void
    {
        $default = $this->paths(null);
        self::assertContains('/alpha-widgets/{id}/-actions/ping', $default);
        self::assertNotContains('/segmentWidgets/{id}/-actions/ping', $default);

        $named = $this->paths(ResourcesOnServerPass::SERVER);
        self::assertContains('/segmentWidgets/{id}/-actions/ping', $named);
        self::assertNotContains('/alpha-widgets/{id}/-actions/ping', $named);

        self::assertSame(204, $this->handle('/alpha-widgets/1/-actions/ping', 'POST')->getStatusCode());
        self::assertSame(204, $this->handle('/v5/segmentWidgets/1/-actions/ping', 'POST')->getStatusCode());
    }

    #[Test]
    public function eachServerDocumentsOnlyItsOwnDescriptionsAndExtensions(): void
    {
        $source = static::getContainer()->get(MetadataSource::class);
        self::assertInstanceOf(MetadataSource::class, $source);

        $default = $this->segmentWidgets($source, null);
        self::assertSame('Alpha widgets.', $default->description());
        self::assertSame('alpha widget', $default->singularName());

        $named = $this->segmentWidgets($source, ResourcesOnServerPass::SERVER);
        self::assertNull($named->description());
        self::assertNull($named->singularName());

        self::assertSame('default', $this->collectionRead(null, '/alpha-widgets')['x-server'] ?? null);
        self::assertArrayNotHasKey('x-server', $this->collectionRead(ResourcesOnServerPass::SERVER, '/segmentWidgets'));
    }

    private function attribute(string $path, string $name): mixed
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);

        return $attributes[$name] ?? null;
    }

    private function segmentWidgets(MetadataSource $source, ?string $server): TypeMetadata
    {
        foreach ($source->forServer($server)->types() as $type) {
            if ($type->type() === 'segmentWidgets') {
                self::assertInstanceOf(TypeMetadata::class, $type);

                return $type;
            }
        }

        self::fail('segmentWidgets is not documented');
    }

    /**
     * The path templates of a server's OpenAPI document, relative to its server URL.
     *
     * @return list<string>
     */
    private function paths(?string $server): array
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $paths = $factory->forServer($server)->toArray()['paths'] ?? null;
        self::assertIsArray($paths);

        return \array_map('strval', \array_keys($paths));
    }

    /**
     * @return array<string, mixed>
     */
    private function collectionRead(?string $server, string $path): array
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $paths = $factory->forServer($server)->toArray()['paths'] ?? null;
        self::assertIsArray($paths);
        $item = $paths[$path] ?? null;
        self::assertIsArray($item, $path);
        $operation = $item['get'] ?? null;
        self::assertIsArray($operation);

        /** @var array<string, mixed> $operation */
        return $operation;
    }
}
