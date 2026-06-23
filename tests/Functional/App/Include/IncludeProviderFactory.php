<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Include;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Seeds the in-memory providers for the include-safeguards suite: a circular
 * `nodes` chain (n1 → n2 → n3 → n1) wired with `next`/`prev` plus a per-node `tag`,
 * the `tags` whose `node` points back, and the `roots`/`caps` holders that each
 * wrap a single `node` into the chain. The object graph is built once so core's
 * accessor reads related **objects** (not ids) when rendering linkage and includes.
 */
final class IncludeProviderFactory
{
    public static function createNodes(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('nodes', self::graph()['nodes']);
    }

    public static function createTags(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('tags', self::graph()['tags']);
    }

    public static function createRoots(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('roots', self::graph()['roots']);
    }

    public static function createCaps(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('caps', self::graph()['caps']);
    }

    public static function createNodesPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('nodes', $provider->store(), static fn(): Node => new Node());
    }

    public static function createTagsPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('tags', $provider->store(), static fn(): Tag => new Tag());
    }

    public static function createRootsPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('roots', $provider->store(), static fn(): Holder => new Holder());
    }

    public static function createCapsPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('caps', $provider->store(), static fn(): Holder => new Holder());
    }

    /**
     * Builds the seeded object graph: three nodes in a circular `next` chain, each
     * with a `prev` and a `tag`, plus the holders.
     *
     * @return array{
     *     nodes: array<string, Node>,
     *     tags: array<string, Tag>,
     *     roots: array<string, Holder>,
     *     caps: array<string, Holder>,
     * }
     */
    private static function graph(): array
    {
        $n1 = new Node('n1', 'one');
        $n2 = new Node('n2', 'two');
        $n3 = new Node('n3', 'three');

        // Circular forward chain: n1 → n2 → n3 → n1.
        $n1->next = $n2;
        $n2->next = $n3;
        $n3->next = $n1;

        // Back-reference (cannotBeIncluded): the inverse of the forward chain.
        $n1->prev = $n3;
        $n2->prev = $n1;
        $n3->prev = $n2;

        $t1 = new Tag('t1', 'alpha', $n1);
        $t2 = new Tag('t2', 'beta', $n2);
        $t3 = new Tag('t3', 'gamma', $n3);

        $n1->tag = $t1;
        $n2->tag = $t2;
        $n3->tag = $t3;

        return [
            'nodes' => ['n1' => $n1, 'n2' => $n2, 'n3' => $n3],
            'tags' => ['t1' => $t1, 't2' => $t2, 't3' => $t3],
            'roots' => ['r1' => new Holder('r1', 'root', $n1)],
            'caps' => ['c1' => new Holder('c1', 'cap', $n1)],
        ];
    }
}
