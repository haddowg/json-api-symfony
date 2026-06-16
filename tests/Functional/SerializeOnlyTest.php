<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\SerializeOnlyTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Routing\RouterInterface;

/**
 * The serialize-only witness (ADR 0024): the `colors` type is registered with a
 * standalone `#[AsJsonApiSerializer]` and no {@see \haddowg\JsonApi\Resource\AbstractResource}.
 * It renders as linkage and as an `?include`d resource on a `widgets` resource, but
 * exposes no endpoints of its own — the case a resource cannot express, because a
 * resource forces endpoints. Storage-orthogonal, so witnessed on the in-memory
 * kernel only.
 */
final class SerializeOnlyTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return SerializeOnlyTestKernel::class;
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function aStandaloneSerializerRendersLinkageOnTheParentResource(): void
    {
        $response = $this->handle('/widgets/w1');
        self::assertSame(200, $response->getStatusCode());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);

        $color = $relationships['color'] ?? null;
        self::assertIsArray($color);

        self::assertSame(['type' => 'colors', 'id' => 'c1'], $color['data'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aStandaloneSerializerFeedsTheIncludedDocument(): void
    {
        $response = $this->handle('/widgets/w1?include=color');
        self::assertSame(200, $response->getStatusCode());

        $included = $this->decode($response)['included'] ?? null;
        self::assertIsArray($included);
        self::assertCount(1, $included);

        $color = $included[0] ?? null;
        self::assertIsArray($color);
        self::assertSame('colors', $color['type'] ?? null);
        self::assertSame('c1', $color['id'] ?? null);

        $attributes = $color['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('Red', $attributes['name'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function theSerializeOnlyTypeHasNoRoutesOfItsOwn(): void
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $paths = [];
        foreach ($router->getRouteCollection() as $route) {
            $paths[] = $route->getPath();
        }

        self::assertContains('/widgets', $paths);
        self::assertNotContains('/colors', $paths);
    }
}
