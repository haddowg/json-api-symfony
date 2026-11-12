<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiBundle\Tests\Functional\App\CatalogConsumer\CapabilityPersister;
use haddowg\JsonApiBundle\Tests\Functional\App\CatalogConsumer\CatalogConsumerTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * A provider and a persister that take the operation catalogue. The catalogue reads the
 * OpenAPI metadata, and the metadata reads the data layer to decide which error codes
 * each operation documents, so the container must build them without going round in
 * a circle.
 */
final class CatalogConsumerTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return CatalogConsumerTestKernel::class;
    }

    #[Test]
    public function aProviderServesRowsBuiltFromTheCatalogue(): void
    {
        $response = $this->handle('/capabilities/update.articles');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame(['method' => 'PATCH'], $data['attributes'] ?? null);
    }

    #[Test]
    public function aPersisterTakingTheCatalogueResolves(): void
    {
        $registry = static::getContainer()->get(DataPersisterRegistry::class);
        \assert($registry instanceof DataPersisterRegistry);

        self::assertInstanceOf(CapabilityPersister::class, $registry->forType('capabilities'));
    }

    #[Test]
    public function theDocumentStillReadsTheDataLayer(): void
    {
        $response = $this->handle('/docs.json');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $paths = $this->decode($response)['paths'] ?? null;
        self::assertIsArray($paths);
        self::assertArrayHasKey('/capabilities', $paths);
        self::assertArrayHasKey('/articles/{id}/relationships/author', $paths);
    }
}
