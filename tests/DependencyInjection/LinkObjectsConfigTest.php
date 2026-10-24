<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DependencyInjection;

use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\OpenApi\DocumentUrlProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * How `json_api.link_objects` (and a named server's own) reaches each server's
 * {@see \haddowg\JsonApiBundle\Server\ServerFactory}, and how
 * `json_api.openapi.describedby` / `document_url` reach the {@see DocumentUrlProvider}.
 */
#[Group('spec:document-links')]
final class LinkObjectsConfigTest extends TestCase
{
    #[Test]
    public function linkObjectsAreOnByDefaultOnEveryServer(): void
    {
        $container = $this->load(['servers' => ['admin' => []]]);

        self::assertTrue($this->linkObjectsOf($container, 'default'));
        self::assertTrue($this->linkObjectsOf($container, 'admin'));
    }

    #[Test]
    public function aNamedServerInheritsTheTopLevelSettingUnlessItSetsItsOwn(): void
    {
        $container = $this->load([
            'link_objects' => false,
            'servers' => [
                'absent' => [],
                'null' => ['link_objects' => null],
                'objects' => ['link_objects' => true],
            ],
        ]);

        self::assertFalse($this->linkObjectsOf($container, 'default'));
        self::assertFalse($this->linkObjectsOf($container, 'absent'));
        self::assertFalse($this->linkObjectsOf($container, 'null'));
        self::assertTrue($this->linkObjectsOf($container, 'objects'));
    }

    #[Test]
    public function describedbyAndTheDocumentUrlReachTheUrlProvider(): void
    {
        $default = $this->load([])->getDefinition(DocumentUrlProvider::class);
        self::assertTrue($default->getArgument('$enabled'));
        self::assertNull($default->getArgument('$configuredUrl'));

        $configured = $this->load(['openapi' => [
            'describedby' => false,
            'document_url' => 'https://docs.example.test/{server}.json',
        ]])->getDefinition(DocumentUrlProvider::class);
        self::assertFalse($configured->getArgument('$enabled'));
        self::assertSame('https://docs.example.test/{server}.json', $configured->getArgument('$configuredUrl'));

        $disabled = $this->load(['openapi' => ['enabled' => false]])->getDefinition(DocumentUrlProvider::class);
        self::assertFalse($disabled->getArgument('$enabled'));
    }

    #[Test]
    public function aNonBooleanServerSettingFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/link_objects must be true, false or null/');

        $this->load(['servers' => ['admin' => ['link_objects' => 'yes']]]);
    }

    #[Test]
    public function anEmptyDocumentUrlFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/document_url must be a non-empty URL/');

        $this->load(['openapi' => ['document_url' => ' ']]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', \sys_get_temp_dir());
        $container->setParameter('kernel.project_dir', \sys_get_temp_dir());
        $container->setParameter('kernel.bundles', []);

        $extension = (new JsonApiBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([$config], $container);

        return $container;
    }

    private function linkObjectsOf(ContainerBuilder $container, string $server): mixed
    {
        return $container->getDefinition(JsonApiBundle::serverFactoryId($server))->getArgument('$linkObjects');
    }
}
