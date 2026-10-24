<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DependencyInjection\Compiler;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApiBundle\DataPersister\Doctrine\DoctrineDataPersister;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineServableWarmer;
use haddowg\JsonApiBundle\DataProvider\Doctrine\PivotAssociationResolver;
use haddowg\JsonApiBundle\DependencyInjection\Compiler\DoctrineEntityMapPass;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Serializer\Doctrine\DoctrineRelationshipLoadState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * The build-time checks of {@see DoctrineEntityMapPass} when `doctrine/orm` is
 * installed (so the Doctrine adapter definitions exist) but the application may or
 * may not provide an `EntityManagerInterface` service.
 */
final class DoctrineEntityMapPassTest extends TestCase
{
    private const array DOCTRINE_DEFINITIONS = [
        DoctrineDataProvider::class,
        DoctrineDataPersister::class,
        DoctrineServableWarmer::class,
        DoctrineRelationshipLoadState::class,
        PivotAssociationResolver::class,
    ];

    #[Test]
    public function anEntityMappingWithoutAnEntityManagerFailsTheBuild(): void
    {
        $container = $this->container(['gadgets' => \ArrayObject::class, 'widgets' => \stdClass::class]);

        try {
            (new DoctrineEntityMapPass())->process($container);
            self::fail('The build should fail.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('types "gadgets" (ArrayObject), "widgets" (stdClass)', $exception->getMessage());
            self::assertStringContainsString(EntityManagerInterface::class, $exception->getMessage());
            self::assertStringContainsString('doctrine/doctrine-bundle', $exception->getMessage());
            self::assertStringContainsString('remove `entity:`', $exception->getMessage());
        }
    }

    #[Test]
    public function theDeclaredMapIsPublishedEvenWhenTheBuildFails(): void
    {
        $container = $this->container(['gadgets' => \stdClass::class]);

        try {
            (new DoctrineEntityMapPass())->process($container);
        } catch (\LogicException) {
        }

        self::assertSame(['gadgets' => \stdClass::class], $container->getParameter(DoctrineEntityMapPass::PARAMETER));
    }

    #[Test]
    public function noEntityMappingWithoutAnEntityManagerBuildsAndDropsTheDoctrineServices(): void
    {
        $container = $this->container([]);

        (new DoctrineEntityMapPass())->process($container);

        foreach (self::DOCTRINE_DEFINITIONS as $id) {
            self::assertFalse($container->hasDefinition($id), $id . ' is removed');
        }
        self::assertSame([], $container->getParameter(DoctrineEntityMapPass::PARAMETER));
    }

    #[Test]
    public function anEntityMappingWithAnEntityManagerAliasBuilds(): void
    {
        $container = $this->container(['gadgets' => \stdClass::class]);
        $container->setDefinition('doctrine.orm.default_entity_manager', new Definition(EntityManagerInterface::class));
        $container->setAlias(EntityManagerInterface::class, 'doctrine.orm.default_entity_manager');

        (new DoctrineEntityMapPass())->process($container);

        self::assertSame(
            ['gadgets' => \stdClass::class],
            $container->getDefinition(DoctrineDataProvider::class)->getArgument('$entityClassByType'),
        );
        self::assertTrue($container->hasDefinition(PivotAssociationResolver::class));
    }

    /**
     * A container holding the Doctrine adapter definitions and one resource per
     * `type => entity` mapping.
     *
     * @param array<string, class-string> $mappings
     */
    private function container(array $mappings): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach (self::DOCTRINE_DEFINITIONS as $id) {
            $container->setDefinition($id, new Definition($id));
        }

        foreach ($mappings as $type => $entity) {
            $container->setDefinition('app.resource.' . $type, (new Definition(\stdClass::class))->addTag(
                JsonApiBundle::RESOURCE_TAG,
                ['type' => $type, 'entity' => $entity],
            ));
        }

        return $container;
    }
}
