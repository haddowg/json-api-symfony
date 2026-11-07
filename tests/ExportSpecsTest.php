<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `bin/export-specs.php` writes what `@haddowg/json-api-symfony-specs` publishes.
 */
final class ExportSpecsTest extends TestCase
{
    private const array DOCUMENTS = [
        'music-catalog.openapi.json',
        'music-catalog-admin.openapi.json',
        'music-catalog.schemas.json',
        'music-catalog-admin.schemas.json',
    ];

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/json-api-symfony-specs-' . \bin2hex(\random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!\is_dir($this->directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() ? \rmdir($file->getPathname()) : \unlink($file->getPathname());
        }
        \rmdir($this->directory);
    }

    #[Test]
    public function itWritesEveryPublishedFile(): void
    {
        $this->export();

        $files = \array_map('basename', \glob($this->directory . '/*') ?: []);
        \sort($files);
        $expected = [...self::DOCUMENTS, 'conformance', 'versions.json'];
        \sort($expected);
        self::assertSame($expected, $files);

        foreach (['music-catalog.openapi.json', 'music-catalog-admin.openapi.json'] as $file) {
            $document = $this->decode($file);
            self::assertSame('3.1.0', $document['openapi'] ?? null, $file);
            $info = $document['info'] ?? null;
            self::assertIsArray($info, $file);
            $generator = $info['x-generator'] ?? null;
            self::assertIsArray($generator, $file);
            self::assertIsInt($generator['contract'] ?? null, $file);
        }

        foreach (['music-catalog.schemas.json', 'music-catalog-admin.schemas.json'] as $file) {
            $albums = $this->decode($file)['albums'] ?? null;
            self::assertIsArray($albums, $file);
            self::assertSame('https://json-schema.org/draft/2020-12/schema', $albums['$schema'] ?? null, $file);
        }

        $versions = $this->decode('versions.json');
        self::assertSame(['haddowg/json-api', 'haddowg/json-api-symfony'], \array_keys($versions));
        self::assertContainsOnlyString($versions);
    }

    #[Test]
    public function itCopiesTheSharedConformanceDocumentsVerbatim(): void
    {
        $this->export();

        $source = \dirname(__DIR__) . '/tests/Functional/App/IdentifiedMorphMany/documents';
        $sources = \glob($source . '/*/*.json') ?: [];
        self::assertNotSame([], $sources);

        $copied = \glob($this->directory . '/conformance/identified-morph-many/*/*.json') ?: [];
        self::assertCount(\count($sources), $copied);
        foreach ($sources as $file) {
            $relative = \substr($file, \strlen($source) + 1);
            self::assertFileEquals($file, $this->directory . '/conformance/identified-morph-many/' . $relative, $relative);
        }
    }

    #[Test]
    public function itWritesTheSameBytesEveryTime(): void
    {
        $this->export();
        $first = [];
        foreach (self::DOCUMENTS as $file) {
            $first[$file] = \file_get_contents($this->directory . '/' . $file);
        }

        $this->export();
        foreach (self::DOCUMENTS as $file) {
            self::assertSame($first[$file], \file_get_contents($this->directory . '/' . $file), $file);
        }
    }

    private function export(): void
    {
        $command = \sprintf(
            '%s %s %s 2>&1',
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg(\dirname(__DIR__) . '/bin/export-specs.php'),
            \escapeshellarg($this->directory),
        );
        \exec($command, $output, $code);

        self::assertSame(0, $code, \implode("\n", $output));
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $file): array
    {
        $contents = \file_get_contents($this->directory . '/' . $file);
        self::assertIsString($contents, $file);
        $decoded = \json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, $file);

        return $decoded;
    }
}
