<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\OpenApi\ArtifactStore;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\OpenApi\DocumentWarmer;
use haddowg\JsonApiBundle\OpenApi\JsonSchemaFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\OpenApiTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The CLI export (D13) and cache-warmer (D17) witnesses: the `json-api:openapi:export`
 * command writes JSON and YAML; the `json-api:json-schema:export` command emits a
 * per-type JSON Schema; and the {@see DocumentWarmer} dumps the per-server artifact the
 * controller then serves O(file read).
 */
final class OpenApiExportAndWarmerTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return OpenApiTestKernel::class;
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theOpenApiExportCommandEmitsValidJsonToStdout(): void
    {
        $tester = $this->command('json-api:openapi:export');
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $decoded = \json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('3.1.0', $decoded['openapi'] ?? null);
        self::assertSame('Catalog API', $this->asArray($decoded['info'] ?? null)['title'] ?? null);
        self::assertArrayHasKey('/products', $this->asArray($decoded['paths'] ?? null));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theOpenApiExportCommandEmitsYaml(): void
    {
        $tester = $this->command('json-api:openapi:export');
        $tester->execute(['--format' => 'yaml']);
        $tester->assertCommandIsSuccessful();

        $decoded = Yaml::parse($tester->getDisplay());
        self::assertIsArray($decoded);
        self::assertSame('3.1.0', $decoded['openapi'] ?? null);
        self::assertArrayHasKey('/products', $this->asArray($decoded['paths'] ?? null));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theJsonSchemaExportCommandEmitsAPerTypeSchema(): void
    {
        $tester = $this->command('json-api:json-schema:export');
        $tester->execute(['--type' => 'products']);
        $tester->assertCommandIsSuccessful();

        $decoded = \json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        // A standalone, self-contained JSON Schema 2020-12 document.
        self::assertSame('https://json-schema.org/draft/2020-12/schema', $decoded['$schema'] ?? null);
        self::assertSame('object', $decoded['type'] ?? null);
        // The products resource object: type const + attributes.
        $properties = $this->asArray($decoded['properties'] ?? null);
        self::assertSame('products', $this->asArray($properties['type'] ?? null)['const'] ?? null);
        self::assertArrayHasKey('name', $this->asArray($this->asArray($properties['attributes'] ?? null)['properties'] ?? null));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theJsonSchemaExportFailsForAnUnknownType(): void
    {
        $tester = $this->command('json-api:json-schema:export');
        $exit = $tester->execute(['--type' => 'totally-made-up-type']);

        // An unknown type fails loudly (a typo must not emit a bogus generic schema).
        // SymfonyStyle wraps the error block, so assert on the (unwrapped) type token.
        self::assertSame(1, $exit);
        self::assertStringContainsString('Unknown JSON:API', $tester->getDisplay());
        self::assertStringContainsString('totally-made-up-type', $tester->getDisplay());
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theWarmerDumpsAnArtifactTheControllerThenServes(): void
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        // The test container exposes private services by their real id.
        $container = static::getContainer();

        $warmer = $container->get(DocumentWarmer::class);
        \assert($warmer instanceof DocumentWarmer);
        self::assertTrue($warmer->isOptional());

        $cacheDir = $kernel->getCacheDir();
        $warmer->warmUp($cacheDir);

        // The warmer wrote the per-server document artifact at the shared path.
        $store = $container->get(ArtifactStore::class);
        \assert($store instanceof ArtifactStore);
        $artifact = $store->read('default');
        self::assertIsString($artifact);
        $decoded = \json_decode($artifact, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('Catalog API', $this->asArray($decoded['info'] ?? null)['title'] ?? null);

        // The controller serves the very artifact the warmer wrote.
        $response = $kernel->handle(Request::create('/docs.json', 'GET'), HttpKernelInterface::MAIN_REQUEST, true);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($artifact, (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theWarmerEmitsStaticFilesToThePublicPath(): void
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $container = static::getContainer();
        $documents = $container->get(DocumentFactory::class);
        \assert($documents instanceof DocumentFactory);
        $schemas = $container->get(JsonSchemaFactory::class);
        \assert($schemas instanceof JsonSchemaFactory);
        $store = $container->get(ArtifactStore::class);
        \assert($store instanceof ArtifactStore);

        $publicPath = \sys_get_temp_dir() . '/json-api-symfony-tests/openapi-public/' . \bin2hex(\random_bytes(6));

        // A warmer configured with a public_path (D17/§6): it also emits a fully static
        // <server>.json (+ .yaml when symfony/yaml is present) the CDN serves with zero
        // PHP. The directory does not exist yet — writeStatic() must mkdir it.
        $warmer = new DocumentWarmer(
            $documents,
            $schemas,
            $store,
            ['default'],
            true,
            false,
            $publicPath,
        );

        $warmer->warmUp($kernel->getCacheDir());

        $jsonFile = $publicPath . '/default.json';
        self::assertFileExists($jsonFile);
        $decoded = \json_decode((string) \file_get_contents($jsonFile), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('3.1.0', $decoded['openapi'] ?? null);

        // symfony/yaml is installed in the test suite, so the .yaml is emitted too.
        $yamlFile = $publicPath . '/default.yaml';
        self::assertFileExists($yamlFile);
        $yaml = Yaml::parse((string) \file_get_contents($yamlFile));
        self::assertIsArray($yaml);
        self::assertSame('3.1.0', $yaml['openapi'] ?? null);

        // Cleanup the temp static artifacts.
        @\unlink($jsonFile);
        @\unlink($yamlFile);
        @\rmdir($publicPath);
    }

    private function command(string $name): CommandTester
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $application = new Application($kernel);
        $command = $application->find($name);
        self::assertTrue($command->getName() === $name || $command->getAliases() !== []);

        return new CommandTester($command);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function asArray(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
