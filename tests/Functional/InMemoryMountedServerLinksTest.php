<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\MountedInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see MountedServerLinksConformanceTestCase} against the in-memory provider.
 */
final class InMemoryMountedServerLinksTest extends MountedServerLinksConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return MountedInMemoryTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-resources')]
    public function theBrowsersExpectedResourceCarriesTheMount(): void
    {
        $provider = static::getContainer()->get('test.articles_provider');
        \assert($provider instanceof InMemoryDataProvider);
        $article = $provider->fetchOne('articles', '1');
        self::assertNotNull($article);

        $expected = $this->browser()->expectResource($article);
        self::assertSame(['self' => ['href' => 'http://localhost/api/v5/articles/1']], $expected['links'] ?? null);

        $this->browser()
            ->get('/api/v5/articles/1')
            ->assertFetchedOneExact($expected);
    }
}
