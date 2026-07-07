<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The per-operation response-declaration witness (core PR — typed response objects).
 * The `catalog-exports` type declares `create: [new Accepted('export-jobs')]` and the
 * `export-jobs` type `fetchOne: [new Ok(), new SeeOther()]`, so this suite proves both
 * the runtime seams (an async `202` accept, and a fetch-one that answers `303` once the
 * job completes / `200` while it is processing) and that those declarations are projected
 * into the generated OpenAPI document. The music-catalog example carries the same two
 * types as the Laravel workbench so the cross-framework byte-compat contract covers them.
 */
#[Group('spec:crud')]
final class ResponseDeclarationTest extends MusicCatalogKernelTestCase
{
    #[Test]
    public function postingACatalogExportIsAcceptedForAsynchronousProcessing(): void
    {
        $response = $this->handle('/catalog-exports', 'POST', [
            'data' => [
                'type' => 'catalog-exports',
                'attributes' => ['format' => 'csv'],
            ],
        ]);

        self::assertSame(202, $response->getStatusCode(), (string) $response->getContent());
        self::assertNotNull($response->headers->get('Content-Location'));
        self::assertSame('30', $response->headers->get('Retry-After'));

        $data = $this->nested($this->decode($response), 'data');
        self::assertSame('export-jobs', $data['type'] ?? null);
    }

    #[Test]
    public function pollingACompletedJobRedirectsToTheProducedExport(): void
    {
        $response = $this->handle('/export-jobs/job-completed');

        self::assertSame(303, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('/catalog-exports/1', $response->headers->get('Location'));
        self::assertSame('', (string) $response->getContent());
    }

    #[Test]
    public function pollingAProcessingJobReturnsTheJobStatus(): void
    {
        $response = $this->handle('/export-jobs/job-processing');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->decode($response);
        $data = $this->nested($document, 'data');
        self::assertSame('export-jobs', $data['type'] ?? null);
        $attributes = $this->nested($document, 'data', 'attributes');
        self::assertSame('processing', $attributes['state'] ?? null);
    }

    #[Test]
    public function theResponseDeclarationsAreProjectedIntoTheOpenApiDocument(): void
    {
        $document = $this->decode($this->handle('/docs.json'));

        self::assertArrayHasKey('202', $this->nested($document, 'paths', '/catalog-exports', 'post', 'responses'));

        $delete = $this->nested($document, 'paths', '/catalog-exports/{id}', 'delete', 'responses');
        self::assertArrayHasKey('204', $delete);
        self::assertArrayHasKey('200', $delete);

        $fetchOne = $this->nested($document, 'paths', '/export-jobs/{id}', 'get', 'responses');
        self::assertArrayHasKey('200', $fetchOne);
        self::assertArrayHasKey('303', $fetchOne);
    }

    /**
     * Walks a decoded document down the given keys, asserting each level is an array
     * and carries the key — so the nested access is PHPStan-clean over `mixed` JSON.
     *
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function nested(array $data, string ...$keys): array
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
