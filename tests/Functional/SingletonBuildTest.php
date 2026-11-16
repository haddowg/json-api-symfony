<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Resource\SingletonMisdeclared;
use haddowg\JsonApiBundle\Tests\Functional\App\SingletonBuild\ListedSettingsResource;
use haddowg\JsonApiBundle\Tests\Functional\App\SingletonBuild\PlainSettingsResource;
use haddowg\JsonApiBundle\Tests\Functional\App\SingletonBuild\ReadOnlySettingsResource;
use haddowg\JsonApiBundle\Tests\Functional\App\SingletonBuild\RelatedOnlySettingsResource;
use haddowg\JsonApiBundle\Tests\Functional\App\SingletonBuild\ResetAllSettings;
use haddowg\JsonApiBundle\Tests\Functional\App\SingletonBuild\SingletonBuildTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\SingletonBuild\UnresolvedSettingsResource;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * A singleton declared with something it cannot serve fails the container build, and a
 * read-only one gets its fetch and relationship read routes alone.
 */
final class SingletonBuildTest extends KernelTestCase
{
    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected static function getKernelClass(): string
    {
        return SingletonBuildTestKernel::class;
    }

    protected function setUp(): void
    {
        $this->errorHandler = \set_error_handler(null);
        \restore_error_handler();
        $this->exceptionHandler = \set_exception_handler(null);
        \restore_exception_handler();
    }

    #[Test]
    public function aSingletonDeclaringACollectionOrDeleteFailsTheBuild(): void
    {
        $this->expectException(SingletonMisdeclared::class);
        $this->expectExceptionMessage('Type "settings" is a singleton, so it serves only fetch and update, but it declares FetchCollection, Delete.');

        $this->boot(ListedSettingsResource::class);
    }

    #[Test]
    public function aCollectionActionOnASingletonFailsTheBuild(): void
    {
        $this->expectException(SingletonMisdeclared::class);
        $this->expectExceptionMessage('Type "settings" is a singleton, so every action on it is resource-scoped, but it declares the collection-scope action "reset-all".');

        $this->boot(PlainSettingsResource::class, ResetAllSettings::class);
    }

    #[Test]
    public function aSingletonThatResolvesNoIdFailsTheBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf('Resource "%s" is a singleton, so it must override resolveSingletonId()', UnresolvedSettingsResource::class));

        $this->boot(UnresolvedSettingsResource::class);
    }

    #[Test]
    public function aRelatedOnlySingletonFailsTheBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The JSON:API type "settings" is a singleton and related-only');

        $this->boot(RelatedOnlySettingsResource::class);
    }

    #[Test]
    public function aSingletonServesFetchAndUpdateByDefault(): void
    {
        $this->boot(PlainSettingsResource::class);

        self::assertSame([
            'jsonapi.settings.related.show' => 'GET /settings/{relationship}',
            'jsonapi.settings.relationship.add' => 'POST /settings/relationships/{relationship}',
            'jsonapi.settings.relationship.remove' => 'DELETE /settings/relationships/{relationship}',
            'jsonapi.settings.relationship.show' => 'GET /settings/relationships/{relationship}',
            'jsonapi.settings.relationship.update' => 'PATCH /settings/relationships/{relationship}',
            'jsonapi.settings.show' => 'GET /settings',
            'jsonapi.settings.update' => 'PATCH /settings',
        ], $this->routes());
    }

    #[Test]
    public function aReadOnlySingletonServesItsFetchAlone(): void
    {
        $this->boot(ReadOnlySettingsResource::class);

        self::assertSame([
            'jsonapi.settings.related.show' => 'GET /settings/{relationship}',
            'jsonapi.settings.relationship.show' => 'GET /settings/relationships/{relationship}',
            'jsonapi.settings.show' => 'GET /settings',
        ], $this->routes());
    }

    /**
     * @param class-string ...$services
     */
    private function boot(string ...$services): void
    {
        static::ensureKernelShutdown();
        SingletonBuildTestKernel::$services = \array_values($services);
        static::bootKernel(['debug' => false]);
    }

    /**
     * @return array<string, string>
     */
    private function routes(): array
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $routes = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            if ($route->getDefault('_jsonapi_type') === 'settings') {
                $routes[$name] = \implode(',', $route->getMethods()) . ' ' . $route->getPath();
            }
        }
        \ksort($routes);

        return $routes;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
        SingletonBuildTestKernel::$services = [];

        while (true) {
            $current = \set_error_handler(static fn(): bool => false);
            \restore_error_handler();
            if ($current === $this->errorHandler) {
                break;
            }
            \restore_error_handler();
        }

        while (true) {
            $current = \set_exception_handler(null);
            \restore_exception_handler();
            if ($current === $this->exceptionHandler) {
                break;
            }
            \restore_exception_handler();
        }
    }
}
