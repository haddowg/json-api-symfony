<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Action\ActionRegistry;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\ResourcesOnServerPass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Each server's OpenAPI document tags a type's operations, and an untagged action on
 * it, with the tags of that server's own resource class (bundle ADR 0034).
 * `taggedWidgets` is tagged Alpha on the default server and Beta on the named one;
 * `plainWidgets` is tagged Gamma on the default server and declares no tags on the
 * named one, which gets the default tag.
 *
 * @phpstan-require-extends JsonApiFunctionalTestCase
 */
trait ServerKeyedOpenApiTagsConformance
{
    #[Test]
    public function eachServersDocumentUsesItsOwnClassesTags(): void
    {
        self::assertSame(['Alpha'], $this->tagsOf(null, '/taggedWidgets', 'get'));
        self::assertSame(['Alpha'], $this->tagsOf(null, '/taggedWidgets/{id}/-actions/ping', 'post'));

        self::assertSame(['Beta'], $this->tagsOf(ResourcesOnServerPass::SERVER, '/taggedWidgets', 'get'));
        self::assertSame(['Beta'], $this->tagsOf(ResourcesOnServerPass::SERVER, '/taggedWidgets/{id}/-actions/ping', 'post'));
    }

    #[Test]
    public function aServerWhoseClassDeclaresNoTagsGetsTheDefaultTag(): void
    {
        self::assertSame(['Gamma'], $this->tagsOf(null, '/plainWidgets', 'get'));
        self::assertSame(['Gamma'], $this->tagsOf(null, '/plainWidgets/{id}/-actions/ping', 'post'));

        $named = $this->tagsOf(ResourcesOnServerPass::SERVER, '/plainWidgets', 'get');
        self::assertNotSame(['Gamma'], $named);
        self::assertSame($named, $this->tagsOf(ResourcesOnServerPass::SERVER, '/plainWidgets/{id}/-actions/ping', 'post'));
    }

    #[Test]
    public function anUntaggedActionIsCompiledWithEachServersResourceTags(): void
    {
        $registry = static::getContainer()->get(ActionRegistry::class);
        self::assertInstanceOf(ActionRegistry::class, $registry);

        self::assertSame(['Alpha'], $registry->descriptorFor('default', 'taggedWidgets', ActionScope::Resource, 'ping')?->tags);
        self::assertSame(['Beta'], $registry->descriptorFor(ResourcesOnServerPass::SERVER, 'taggedWidgets', ActionScope::Resource, 'ping')?->tags);
        self::assertSame([], $registry->descriptorFor(ResourcesOnServerPass::SERVER, 'plainWidgets', ActionScope::Resource, 'ping')?->tags);
    }

    /**
     * The tags of the operation at the path ending in `$suffix` in a server's document.
     *
     * @return list<string>
     */
    private function tagsOf(?string $server, string $suffix, string $method): array
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $paths = $factory->forServer($server)->toArray()['paths'] ?? null;
        self::assertIsArray($paths);

        foreach ($paths as $path => $item) {
            if (\str_ends_with((string) $path, $suffix)) {
                self::assertIsArray($item);
                $operation = $item[$method] ?? null;
                self::assertIsArray($operation, $path . ' ' . $method);
                $tags = $operation['tags'] ?? null;
                self::assertIsArray($tags);

                /** @var list<string> $tags */
                return $tags;
            }
        }

        self::fail(\sprintf('No path ends in %s: %s', $suffix, \implode(', ', \array_map('strval', \array_keys($paths)))));
    }
}
