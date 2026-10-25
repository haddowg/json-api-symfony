<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\SecuredWidgetEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\SecurityTestKernel;

/**
 * {@see RelatedRowReadGateConformance} on the Doctrine provider.
 */
class DoctrineRelatedRowReadGateTest extends JsonApiFunctionalTestCase
{
    use RelatedRowReadGateConformance;

    protected static function getKernelClass(): string
    {
        return SecurityTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // Ids are generated, so insert the widgets before linking them.
        $widgets = [];
        foreach ([1 => 'one', 2 => 'two', 3 => 'classified', 4 => 'four'] as $id => $name) {
            $widgets[$id] = new SecuredWidgetEntity($id, $name);
            $entityManager->persist($widgets[$id]);
        }
        $entityManager->flush();

        $widgets[1]->partner = $widgets[3];
        $widgets[1]->partners?->add($widgets[2]);
        $widgets[1]->partners?->add($widgets[3]);
        $widgets[4]->partner = $widgets[1];
        $entityManager->flush();
        $entityManager->clear();
    }
}
