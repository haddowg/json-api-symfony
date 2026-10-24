<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\Components;
use haddowg\JsonApi\OpenApi\Info;
use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApi\OpenApi\Serve\ServeArtifact;
use haddowg\JsonApi\OpenApi\Serve\ServeArtifactCodec;
use haddowg\JsonApiBundle\OpenApi\ArtifactStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ArtifactStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/json-api-symfony-tests/artifact-store-' . \bin2hex(\random_bytes(4));
    }

    protected function tearDown(): void
    {
        $base = $this->dir . '/json_api_openapi';
        foreach (\glob($base . '/*') ?: [] as $file) {
            \unlink($file);
        }
        if (\is_dir($base)) {
            \rmdir($base);
        }
        if (\is_dir($this->dir)) {
            \rmdir($this->dir);
        }
    }

    #[Test]
    public function aWrittenArtifactReadsBack(): void
    {
        $store = new ArtifactStore($this->dir);
        $store->writeServeArtifact('default', ServeArtifact::of($this->document()));

        self::assertSame('Catalog', $store->readServeArtifact('default')?->document->info->title);
    }

    #[Test]
    public function anArtifactWrittenUnderAnotherFingerprintReadsAsMissing(): void
    {
        // The same artifact, encoded by a codec that fingerprints one more package, stands
        // in for one written before a deploy changed an installed version.
        $codec = new ServeArtifactCodec([ArtifactStore::PACKAGE, 'phpunit/phpunit']);
        $bytes = $codec->encode(ServeArtifact::of($this->document()));
        self::assertNotNull($codec->decode($bytes));

        $store = new ArtifactStore($this->dir);
        $store->writeServeArtifact('default', ServeArtifact::of($this->document()));
        $path = $this->dir . '/json_api_openapi/default.serve';
        self::assertFileExists($path);
        \file_put_contents($path, $bytes);

        self::assertNull($store->readServeArtifact('default'));
    }

    #[Test]
    public function aDocumentHoldingAnUnlistedObjectCantBeWrittenUnlessItsClassIsAllowed(): void
    {
        $document = $this->document()->withInfo((new Info('Catalog', '1.0.0'))->withExtension('x-released', new \DateTimeImmutable('2026-01-01')));

        try {
            (new ArtifactStore($this->dir))->writeServeArtifact('default', ServeArtifact::of($document));
            self::fail('Expected the codec to refuse the object.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('DateTimeImmutable', $e->getMessage());
        }

        $store = new ArtifactStore($this->dir, [\DateTimeImmutable::class]);
        $store->writeServeArtifact('default', ServeArtifact::of($document));
        self::assertInstanceOf(\DateTimeImmutable::class, $store->readServeArtifact('default')?->document->info->extension('x-released'));
        self::assertInstanceOf(\DateTimeImmutable::class, $store->withCacheDir($this->dir)->readServeArtifact('default')?->document->info->extension('x-released'));
    }

    private function document(): OpenApi
    {
        return new OpenApi(new Info('Catalog', '1.0.0'), new Components());
    }
}
