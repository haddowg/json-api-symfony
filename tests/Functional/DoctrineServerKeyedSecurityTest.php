<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\SecuredWidgetEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\BothServersSecurityTestKernel;

/**
 * {@see ServerKeyedSecurityConformance} on the Doctrine provider.
 */
final class DoctrineServerKeyedSecurityTest extends JsonApiFunctionalTestCase
{
    use ServerKeyedSecurityConformance;

    protected static function getKernelClass(): string
    {
        return BothServersSecurityTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $widgets = [];
        foreach ([1 => 'one', 2 => 'two', 3 => 'classified', 4 => 'four'] as $id => $name) {
            $widgets[$id] = new SecuredWidgetEntity($id, $name);
            $entityManager->persist($widgets[$id]);
        }
        $entityManager->flush();

        $widgets[1]->partner = $widgets[3];
        $widgets[1]->partners?->add($widgets[2]);
        $widgets[1]->partners?->add($widgets[3]);
        $entityManager->flush();
        $entityManager->clear();
    }
}
