<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Composite\CompositeInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `--compact` on the export commands. The composite kernel's schemas carry empty
 * objects, so the tests can check they stay `{}` in the one-line form.
 */
final class CompactExportTest extends JsonApiFunctionalTestCase
{
    private const FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    private string $dir;

    protected static function getKernelClass(): string
    {
        return CompositeInMemoryTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = \sys_get_temp_dir() . '/json-api-symfony-tests/compact-export/' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) \glob($this->dir . '/*') as $file) {
            @\unlink((string) $file);
        }
        @\rmdir($this->dir);
        parent::tearDown();
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theOpenApiExportWritesCompactJsonToStdout(): void
    {
        $this->assertCompactFormOf(
            $this->export('json-api:openapi:export', []),
            $this->export('json-api:openapi:export', ['--compact' => true]),
            "\n",
        );
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theOpenApiExportWritesCompactJsonToAFile(): void
    {
        $file = $this->dir . '/openapi.json';
        $this->export('json-api:openapi:export', ['--compact' => true, '--output' => $file]);

        $this->assertCompactFormOf(
            $this->export('json-api:openapi:export', []),
            (string) \file_get_contents($file),
            "\n",
        );
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theOpenApiExportRejectsCompactYaml(): void
    {
        $tester = $this->command('json-api:openapi:export');
        $exit = $tester->execute(['--compact' => true, '--format' => 'yaml']);

        self::assertSame(2, $exit);
        self::assertStringContainsString('--compact', $tester->getDisplay());
        self::assertStringNotContainsString('openapi:', $tester->getDisplay());
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theJsonSchemaExportWritesCompactJsonToStdout(): void
    {
        $this->assertCompactFormOf(
            $this->export('json-api:json-schema:export', ['--type' => 'composites']),
            $this->export('json-api:json-schema:export', ['--type' => 'composites', '--compact' => true]),
            "\n",
        );
        $this->assertCompactFormOf(
            $this->export('json-api:json-schema:export', []),
            $this->export('json-api:json-schema:export', ['--compact' => true]),
            "\n",
        );
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theJsonSchemaExportWritesCompactJsonToFiles(): void
    {
        $pretty = $this->dir . '/pretty.json';
        $compact = $this->dir . '/compact.json';
        $this->export('json-api:json-schema:export', ['--type' => 'composites', '--output' => $pretty]);
        $this->export('json-api:json-schema:export', ['--type' => 'composites', '--output' => $compact, '--compact' => true]);

        // A file export has no trailing newline in either form.
        $this->assertCompactFormOf((string) \file_get_contents($pretty), (string) \file_get_contents($compact), '');
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theJsonSchemaExportWritesCompactJsonToADirectory(): void
    {
        $prettyDir = $this->dir . '/pretty';
        $compactDir = $this->dir . '/compact';
        $this->export('json-api:json-schema:export', ['--output' => $prettyDir]);
        $this->export('json-api:json-schema:export', ['--output' => $compactDir, '--compact' => true]);

        foreach (['composites', 'checklists'] as $type) {
            $this->assertCompactFormOf(
                (string) \file_get_contents($prettyDir . '/' . $type . '.json'),
                (string) \file_get_contents($compactDir . '/' . $type . '.json'),
                '',
            );
            @\unlink($prettyDir . '/' . $type . '.json');
            @\unlink($compactDir . '/' . $type . '.json');
        }
        @\rmdir($prettyDir);
        @\rmdir($compactDir);
    }

    /**
     * The compact document is the pretty one re-encoded without JSON_PRETTY_PRINT: the
     * same structure, empty objects still `{}`, one line, slashes unescaped.
     */
    private function assertCompactFormOf(string $pretty, string $compact, string $trailing): void
    {
        $structure = \json_decode($pretty, false, 512, \JSON_THROW_ON_ERROR);
        self::assertEquals($structure, \json_decode($compact, false, 512, \JSON_THROW_ON_ERROR));
        self::assertSame(\json_encode($structure, self::FLAGS) . $trailing, $compact);

        $body = $trailing === '' ? $compact : \substr($compact, 0, -\strlen($trailing));
        self::assertStringNotContainsString("\n", $body);
        self::assertStringStartsWith('{"', $body);
        self::assertStringContainsString('{}', $body);
        self::assertSame(\substr_count($pretty, '{}'), \substr_count($body, '{}'));
        self::assertStringContainsString('https://', $body);
        self::assertStringNotContainsString('\/', $body);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function export(string $name, array $input): string
    {
        $tester = $this->command($name);
        $tester->execute($input);
        $tester->assertCommandIsSuccessful();

        return $tester->getDisplay();
    }

    private function command(string $name): CommandTester
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester((new Application($kernel))->find($name));
    }
}
