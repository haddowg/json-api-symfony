<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\AboveFallbackArticleProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\OverridingArticleProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ProviderOverrideTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * The provider-precedence contract: an application provider registered by
 * plain autoconfiguration (default tag priority `0`) shadows the bundled
 * Doctrine fallback (`-128`) for the types it supports — no priority
 * configuration required. The {@see ProviderOverrideTestKernel} registers
 * three providers for `articles` and never creates the database schema, so a
 * successful read is attributable to the override alone.
 *
 * Definition order alone *also* happens to place app services before the
 * bundle's, so the shadowing assertions cannot distinguish priority sorting
 * from coincidence — the {@see AboveFallbackArticleProvider} (an app service
 * tagged `-64`, between the default and the fallback) exists to break that
 * tie: it sorts before the Doctrine provider only because the bundle
 * registers the fallback with a sufficiently negative priority, while a
 * bare-tagged Doctrine provider would tie with the default at `0` and
 * outrank it.
 */
final class DataProviderPriorityTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return ProviderOverrideTestKernel::class;
    }

    #[Test]
    public function anApplicationProviderShadowsTheDoctrineFallbackForItsType(): void
    {
        $registry = static::getContainer()->get(DataProviderRegistry::class);
        \assert($registry instanceof DataProviderRegistry);

        self::assertInstanceOf(OverridingArticleProvider::class, $registry->forType('articles'));

        // The Doctrine provider is still wired (the resource maps an entity) —
        // the application provider wins by priority, not by absence.
        self::assertInstanceOf(DoctrineDataProvider::class, static::getContainer()->get(DoctrineDataProvider::class));
    }

    #[Test]
    public function providersAreConsultedInDescendingTagPriorityOrder(): void
    {
        $registry = static::getContainer()->get(DataProviderRegistry::class);
        \assert($registry instanceof DataProviderRegistry);

        // White-box on purpose: every registered provider supports `articles`,
        // so `forType()` can only ever observe the head of the order. The
        // AboveFallback-before-Doctrine middle is the assertion that bites:
        // it holds only while the bundle tags its fallback below -64.
        $providers = (new \ReflectionProperty(DataProviderRegistry::class, 'providers'))->getValue($registry);
        self::assertIsArray($providers);

        $classes = [];
        foreach ($providers as $provider) {
            self::assertIsObject($provider);
            $classes[] = $provider::class;
        }

        self::assertSame([
            OverridingArticleProvider::class,    //    0 — the application override
            AboveFallbackArticleProvider::class, //  -64 — between default and fallback
            DoctrineDataProvider::class,         // -128 — the bundled fallback, always last
        ], $classes);
    }

    #[Test]
    public function aReadOnTheOverriddenTypeIsServedByTheApplicationProvider(): void
    {
        $response = $this->handle('/articles/1');

        self::assertSame(200, $response->getStatusCode());

        $document = $this->decode($response);

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('articles', $data['type'] ?? null);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame(OverridingArticleProvider::TITLE, $attributes['title'] ?? null);
    }
}
