<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Include\Doctrine\HolderEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Include\Doctrine\NodeEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Include\Doctrine\NodeEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Include\Doctrine\TagEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Include\Doctrine\TagEntityFactory;

/**
 * Shared `afterBoot()` for the Doctrine include-safeguards suite: creates the
 * in-memory SQLite schema and seeds the circular `nodes` chain (n1 → n2 → n3 → n1,
 * with `prev` the inverse and a per-node `tag`), the `tags` whose `node` points
 * back, and the `roots`/`caps` holders that wrap n1. The nodes are created first
 * with null associations, then wired and flushed once so the circular foreign keys
 * resolve, then the unit of work is cleared so the request issues fresh queries.
 */
trait SeedsIncludeSafeguards
{
    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // The three chain nodes, created without associations first so the circular
        // `next`/`prev` foreign keys can be wired before a single flush.
        $n1 = NodeEntityFactory::createOne(['id' => 'n1', 'label' => 'one']);
        $n2 = NodeEntityFactory::createOne(['id' => 'n2', 'label' => 'two']);
        $n3 = NodeEntityFactory::createOne(['id' => 'n3', 'label' => 'three']);

        $t1 = TagEntityFactory::createOne(['id' => 't1', 'name' => 'alpha', 'node' => $n1]);
        $t2 = TagEntityFactory::createOne(['id' => 't2', 'name' => 'beta', 'node' => $n2]);
        $t3 = TagEntityFactory::createOne(['id' => 't3', 'name' => 'gamma', 'node' => $n3]);

        // Circular forward chain and its inverse, plus the per-node tag.
        $this->link($n1, next: $n2, prev: $n3, tag: $t1);
        $this->link($n2, next: $n3, prev: $n1, tag: $t2);
        $this->link($n3, next: $n1, prev: $n2, tag: $t3);

        HolderEntityFactory::createOne(['id' => 'r1', 'label' => 'root', 'kind' => 'root', 'node' => $n1]);
        HolderEntityFactory::createOne(['id' => 'c1', 'label' => 'cap', 'kind' => 'cap', 'node' => $n1]);

        $entityManager->flush();
        $entityManager->clear();
    }

    private function link(NodeEntity $node, NodeEntity $next, NodeEntity $prev, TagEntity $tag): void
    {
        $node->next = $next;
        $node->prev = $prev;
        $node->tag = $tag;
    }
}
