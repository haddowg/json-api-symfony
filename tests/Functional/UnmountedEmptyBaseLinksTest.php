<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\EmptyBaseWritableInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * An empty `base_uri` on a server imported with no prefix: links stay based on the
 * request origin alone, as they were before mounts were recorded (bundle ADR 0121).
 */
final class UnmountedEmptyBaseLinksTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return EmptyBaseWritableInMemoryTestKernel::class;
    }

    #[Test]
    #[Group('spec:document-links')]
    public function resourceAndDocumentLinksUseTheRequestOrigin(): void
    {
        $response = $this->handle('https://tenant.example/articles/1');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->decode($response);
        self::assertSame(['self' => 'https://tenant.example/articles/1'], $document['links'] ?? null);

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame(['self' => 'https://tenant.example/articles/1'], $data['links'] ?? null);
    }

    #[Test]
    #[Group('spec:document-links')]
    public function paginationLinksUseTheRequestOrigin(): void
    {
        $response = $this->handle('/articles?page[size]=2');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $links = $this->decode($response)['links'] ?? null;
        self::assertIsArray($links);
        self::assertSame('http://localhost/articles?page%5Bnumber%5D=2&page%5Bsize%5D=2', $links['next'] ?? null);
    }
}
