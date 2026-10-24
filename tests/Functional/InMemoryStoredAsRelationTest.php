<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\OpenApiTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * `products.tags` is a to-many stored under a renamed property (`storedAs('tagIds')`)
 * on the in-memory store. Every read path renders the stored categories.
 */
final class InMemoryStoredAsRelationTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return OpenApiTestKernel::class;
    }

    #[Test]
    public function theRelatedEndpointRendersTheStoredCategories(): void
    {
        $response = $this->handle('/products/1/tags');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->nested($this->decode($response), 'data');
        self::assertCount(1, $data);
        $category = $this->nested($data, 0);
        self::assertSame(['categories', '1'], [$category['type'] ?? null, $category['id'] ?? null]);
        self::assertSame('Tools', $this->nested($category, 'attributes')['name'] ?? null);
    }

    #[Test]
    public function theRelationshipEndpointRendersTheStoredLinkage(): void
    {
        $response = $this->handle('/products/1/relationships/tags');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([['type' => 'categories', 'id' => '1']], $this->decode($response)['data'] ?? null);
    }

    #[Test]
    public function anIncludeRendersTheLinkageAndTheIncludedCategory(): void
    {
        $response = $this->handle('/products/1?include=tags');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);
        self::assertSame(
            [['type' => 'categories', 'id' => '1']],
            $this->nested($document, 'data', 'relationships', 'tags', 'data'),
        );
        $included = $this->nested($document, 'included');
        self::assertCount(1, $included);
        $category = $this->nested($included, 0);
        self::assertSame(['categories', '1'], [$category['type'] ?? null, $category['id'] ?? null]);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function nested(array $data, string|int ...$keys): array
    {
        $current = $data;
        foreach ($keys as $key) {
            self::assertIsArray($current);
            self::assertArrayHasKey($key, $current);
            $current = $current[$key];
        }
        self::assertIsArray($current);

        return $current;
    }
}
