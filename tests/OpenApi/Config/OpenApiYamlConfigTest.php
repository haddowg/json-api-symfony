<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\OpenApi\Config;

use haddowg\JsonApiBundle\OpenApi\Config\OpenApiConfigResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * `json_api.openapi.yaml`: on by default only when symfony/yaml is installed, and turning
 * it on without symfony/yaml fails the container build.
 */
#[Group('spec:openapi')]
final class OpenApiYamlConfigTest extends TestCase
{
    #[Test]
    public function yamlIsOnByDefaultWhenSymfonyYamlIsInstalled(): void
    {
        $config = (new OpenApiConfigResolver(yamlAvailable: true))->resolve(['openapi' => ['yaml' => ['enabled' => null, 'path' => '/docs.yaml']]], ['default']);

        self::assertTrue($config->yamlEnabled);
        self::assertSame('/docs.yaml', $config->yamlPath);
        self::assertTrue((new OpenApiConfigResolver(yamlAvailable: true))->resolve([], ['default'])->yamlEnabled);
    }

    #[Test]
    public function yamlIsOffByDefaultWithoutSymfonyYaml(): void
    {
        $resolver = new OpenApiConfigResolver(yamlAvailable: false);

        self::assertFalse($resolver->resolve(['openapi' => ['yaml' => ['enabled' => null]]], ['default'])->yamlEnabled);
        self::assertFalse($resolver->resolve(['openapi' => ['yaml' => ['enabled' => false]]], ['default'])->yamlEnabled);
    }

    #[Test]
    public function turningYamlOnWithoutSymfonyYamlFails(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('composer require symfony/yaml');

        (new OpenApiConfigResolver(yamlAvailable: false))->resolve(['openapi' => ['yaml' => ['enabled' => true]]], ['default']);
    }

    #[Test]
    public function thePathIsNormalisedToLeadWithASlash(): void
    {
        self::assertSame('/openapi.yaml', (new OpenApiConfigResolver(yamlAvailable: true))->resolve(['openapi' => ['yaml' => ['path' => 'openapi.yaml']]], ['default'])->yamlPath);
    }
}
