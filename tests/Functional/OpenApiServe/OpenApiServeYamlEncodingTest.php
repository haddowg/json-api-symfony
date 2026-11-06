<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\OpenApiServe;

use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve\OpenApiServeTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

/**
 * Every YAML encoding of the document, served, exported and written to `public_path`, keeps
 * an operation's `security: []` a sequence and an empty object a map, so it reads back as
 * the JSON document.
 */
final class OpenApiServeYamlEncodingTest extends OpenApiServeTestCase
{
    protected static function environment(): string
    {
        return 'public_op';
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theServedYamlKeepsAnEmptySecurityList(): void
    {
        $json = $this->get('/docs.json');
        self::assertSame([], self::arr(self::arr(self::arr($this->json($json)['paths'] ?? null)['/products'] ?? null)['get'] ?? null)['security'] ?? null);

        $yaml = (string) $this->get('/docs.yaml')->getContent();
        self::assertStringContainsString('security: []', $yaml);
        self::assertSameDocument((string) $json->getContent(), $yaml);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theExportedYamlKeepsAnEmptySecurityList(): void
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);
        $json = new CommandTester((new Application($kernel))->find('json-api:openapi:export'));
        $json->execute([]);
        $yaml = new CommandTester((new Application($kernel))->find('json-api:openapi:export'));
        $yaml->execute(['--format' => 'yaml']);
        $yaml->assertCommandIsSuccessful();

        self::assertStringContainsString('security: []', $yaml->getDisplay());
        self::assertSameDocument($json->getDisplay(), $yaml->getDisplay());
    }

    #[Test]
    #[Group('spec:openapi')]
    public function thePublicPathYamlKeepsAnEmptySecurityList(): void
    {
        $this->warm();
        $kernel = static::$kernel;
        \assert($kernel instanceof OpenApiServeTestKernel);
        $base = $kernel->getCacheDir() . '/public/default';

        $yaml = (string) \file_get_contents($base . '.yaml');
        self::assertStringContainsString('security: []', $yaml);
        self::assertSameDocument((string) \file_get_contents($base . '.json'), $yaml);
    }

    private static function assertSameDocument(string $json, string $yaml): void
    {
        $fromJson = \json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(
            \json_encode($fromJson, \JSON_THROW_ON_ERROR),
            \json_encode(Yaml::parse($yaml, Yaml::PARSE_OBJECT_FOR_MAP), \JSON_THROW_ON_ERROR),
        );
        // The document has empty objects too, which must stay maps.
        self::assertStringContainsString('{}', (string) \json_encode($fromJson, \JSON_THROW_ON_ERROR));
    }
}
