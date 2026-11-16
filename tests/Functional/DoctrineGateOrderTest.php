<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\GateOrder\Doctrine\GateDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\GateOrder\GateFactory;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see GateOrderConformanceTestCase} on Doctrine over SQLite, plus the member-move gate,
 * which only the Doctrine persister reports.
 */
final class DoctrineGateOrderTest extends GateOrderConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return GateDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // One flush per widget, so each takes the id the in-memory seed gives it.
        $widgets = GateFactory::seed();
        $relations = [];
        foreach ($widgets as $id => $widget) {
            $relations[$id] = [$widget->partner, $widget->parent, $widget->children->toArray()];
            $widget->partner = $widget->parent = null;
            $widget->children->clear();
            $entityManager->persist($widget);
            $entityManager->flush();
        }
        foreach ($widgets as $id => $widget) {
            [$widget->partner, $widget->parent] = $relations[$id];
            foreach ($relations[$id][2] as $child) {
                $widget->children->add($child);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function aRefusedMemberMoveRunsNoHook(): void
    {
        // The caller may change widget 1, but widget 4 sits under widget 2, which they
        // may not change, and is not theirs either.
        $this->assertRefused($this->as('user', '/gateWidgets/1/relationships/children', 'POST', ['data' => [['type' => 'gateWidgets', 'id' => '4']]]));
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
