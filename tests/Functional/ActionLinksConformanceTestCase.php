<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ActionLinks\ActionLinksInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Action links a resource declares (bundle ADR 0076), run against the in-memory
 * ({@see InMemoryActionLinksTest}) and Doctrine ({@see DoctrineActionLinksTest}) kernels.
 *
 * Articles 1, 2 and 4 are guides and 3 and 5 are news. `review` links guides, `feature`
 * links guides for admins, and `archive`, an `asLink` action, links news.
 */
#[Group('spec:actions')]
abstract class ActionLinksConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const array ADMIN = ['HTTP_AUTHORIZATION' => 'Bearer admin'];

    private const array USER = ['HTTP_AUTHORIZATION' => 'Bearer user'];

    #[Test]
    public function aDeclaredActionLinkRendersWhileItsConditionHolds(): void
    {
        $links = $this->linksOf('/api/articles/1');

        self::assertSame(
            [
                'href' => 'http://localhost/api/articles/1/-actions/review',
                'describedby' => '/api/docs.json#/paths/~1articles~1%7Bid%7D~1-actions~1review/post',
            ],
            $links['review'] ?? null,
        );
    }

    #[Test]
    public function aDeclaredActionLinkIsAbsentWhenItsConditionFails(): void
    {
        self::assertArrayNotHasKey('review', $this->linksOf('/api/articles/3'));
    }

    #[Test]
    public function eachCollectionMemberIsJudgedOnItsOwnState(): void
    {
        $response = $this->handle('/api/articles');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        $linked = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $links = $resource['links'] ?? null;
            self::assertIsArray($links);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $linked[$id] = \array_values(\array_diff(\array_keys($links), ['self']));
        }

        self::assertSame(['1' => ['review'], '2' => ['review'], '3' => ['archive'], '4' => ['review'], '5' => ['archive']], $linked);
    }

    #[Test]
    public function theActionsSecurityStillGatesTheLink(): void
    {
        self::assertArrayHasKey('feature', $this->linksOf('/api/articles/1', self::ADMIN));
        self::assertArrayNotHasKey('feature', $this->linksOf('/api/articles/1', self::USER));
        self::assertArrayNotHasKey('feature', $this->linksOf('/api/articles/1'));
        // Security passes, the condition doesn't.
        self::assertArrayNotHasKey('feature', $this->linksOf('/api/articles/3', self::ADMIN));
    }

    #[Test]
    public function theConditionWinsOverAsLink(): void
    {
        self::assertArrayNotHasKey('archive', $this->linksOf('/api/articles/1'));
        self::assertSame('http://localhost/api/articles/3/-actions/archive', self::at($this->linksOf('/api/articles/3'), 'archive', 'href'));
    }

    #[Test]
    public function theLinkIsOnTheConfiguredBaseUri(): void
    {
        $this->bootEnvironment('configured');

        $links = $this->linksOf('https://tenant.example/api/articles/1');

        $base = ActionLinksInMemoryTestKernel::CONFIGURED_BASE_URI;
        self::assertSame($base . '/articles/1', self::at($links, 'self', 'href'));
        self::assertSame($base . '/articles/1/-actions/review', self::at($links, 'review', 'href'));
    }

    #[Test]
    public function theLinkIsOnTheRequestHostAndMountWhenTheBaseUriIsEmpty(): void
    {
        $links = $this->linksOf('https://tenant.example/api/articles/1');

        self::assertSame('https://tenant.example/api/articles/1', self::at($links, 'self', 'href'));
        self::assertSame('https://tenant.example/api/articles/1/-actions/review', self::at($links, 'review', 'href'));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theDocumentDescribesEachActionLink(): void
    {
        $response = $this->handle('/api/docs.json');
        self::assertSame(200, $response->getStatusCode());
        $schemas = self::at($this->decode($response), 'components', 'schemas');
        self::assertSame(['$ref' => '#/components/schemas/ResourceLinks'], self::at($schemas, 'ArticlesResourceLinks', 'allOf', 0));
        self::assertIsArray(self::at($schemas, 'ResourceLinks', 'properties', 'self'));
        $properties = self::at($schemas, 'ArticlesResourceLinks', 'allOf', 1, 'properties');
        self::assertIsArray($properties);

        self::assertSame(['review', 'feature', 'archive'], \array_keys($properties));
        foreach ($properties as $name => $link) {
            self::assertSame('#/components/schemas/RenderedLink', self::at($link, '$ref'), $name);
        }
        self::assertSame('Present while the article is a guide.', self::string(self::at($properties, 'review', 'description')));
        self::assertSame('The `feature` action on this resource.', self::string(self::at($properties, 'feature', 'description')));
        self::assertSame('Present while the article is news.', self::string(self::at($properties, 'archive', 'description')));
    }

    #[Test]
    public function anActionLinkNamingNoActionFailsTheBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/resource .*ActionLinkArticleResource declares an action link for "retract", but no server serving type "articles" has a resource-scope action with that path/');

        $this->bootEnvironment('missing', cold: true);
    }

    #[Test]
    public function aDeclaredLinkNamedLikeAnActionLinkFailsTheBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Resource ".*ActionLinkArticleResource" declares the link "review" and an action link for the "review" action/');

        $this->bootEnvironment('clash', cold: true);
    }

    #[Test]
    public function twoActionLinksForOnePathFailTheBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Resource ".*ActionLinkArticleResource" declares the action link "review" twice/');

        $this->bootEnvironment('duplicate', cold: true);
    }

    /**
     * @param array<string, string> $server
     *
     * @return array<string, mixed>
     */
    private function linksOf(string $uri, array $server = []): array
    {
        $response = $this->handle($uri, 'GET', null, $server);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $links = self::at($this->decode($response), 'data', 'links');
        self::assertIsArray($links);

        /** @var array<string, mixed> $links */
        return $links;
    }

    private function bootEnvironment(string $environment, bool $cold = false): void
    {
        self::ensureKernelShutdown();
        if ($cold) {
            $this->removeDir(static::createKernel(['debug' => false, 'environment' => $environment])->getCacheDir());
        }
        static::bootKernel(['debug' => false, 'environment' => $environment]);
        $this->afterBoot();
    }

    private function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->isDir()) {
                @\rmdir($file->getPathname());
            } else {
                @\unlink($file->getPathname());
            }
        }

        @\rmdir($dir);
    }

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private static function at(mixed $value, int|string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
