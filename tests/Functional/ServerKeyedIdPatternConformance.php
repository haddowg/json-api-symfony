<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource;
use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\ResourcesOnServerPass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RouterInterface;

/**
 * `patternedWidgets` is served by a different class on each server:
 * the default server's has a numeric id, the named server's a lower-case letters id.
 * Each server's routes and OpenAPI document follow its own class's id pattern.
 *
 * @phpstan-require-extends JsonApiFunctionalTestCase
 */
trait ServerKeyedIdPatternConformance
{
    #[Test]
    public function eachServerRoutesItsOwnIdForm(): void
    {
        self::assertTrue($this->routes('/patternedWidgets/1'));
        self::assertFalse($this->routes('/patternedWidgets/abc'));

        self::assertTrue($this->routes('/v5/patternedWidgets/abc'));
        self::assertFalse($this->routes('/v5/patternedWidgets/1'));
    }

    #[Test]
    public function eachServerDocumentsItsOwnIdPattern(): void
    {
        $source = static::getContainer()->get(MetadataSource::class);
        self::assertInstanceOf(MetadataSource::class, $source);

        /** @var array<string, string|null> $patterns */
        $patterns = [];
        foreach ([null, ResourcesOnServerPass::SERVER] as $server) {
            foreach ($source->forServer($server)->types() as $type) {
                if ($type->type() === 'patternedWidgets') {
                    $patterns[$server ?? 'default'] = $type->idPattern();
                }
            }
        }

        self::assertSame(['default', ResourcesOnServerPass::SERVER], \array_keys($patterns));
        self::assertNotSame('[a-z]+', $patterns['default'] ?? null);
        self::assertSame('[a-z]+', $patterns[ResourcesOnServerPass::SERVER] ?? null);
    }

    private function routes(string $path): bool
    {
        $router = static::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);

        try {
            $router->match($path);

            return true;
        } catch (ResourceNotFoundException) {
            return false;
        }
    }
}
