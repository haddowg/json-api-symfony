<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Include\Doctrine\DoctrineIncludeSafeguardsTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see IncludeSafeguardsConformanceTestCase} against the Doctrine provider: the
 * same include-safeguard assertions as the in-memory suite, executed as real DQL
 * over an in-memory SQLite database created per test and seeded with the circular
 * `nodes` chain. The Doctrine batch include-preloader (when
 * `shipmonk/doctrine-entity-preloader` is installed) walks the same effective tree,
 * so the safeguards bound the real preload recursion too — a too-deep request 400s
 * before the preloader runs, and the mutual default-include cycle terminates at the
 * cap rather than recursing the preloader forever (bundle ADR 0037).
 */
final class DoctrineIncludeSafeguardsTest extends IncludeSafeguardsConformanceTestCase
{
    use SeedsIncludeSafeguards;

    protected static function getKernelClass(): string
    {
        return DoctrineIncludeSafeguardsTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function theDoctrinePreloaderBatchesTheCappedTreeWithoutOverRunningTheCap(): void
    {
        // depth(next.next.next) = 3 = the cap. The Doctrine batch-preloader walks
        // the circular chain n1 → n2 → n3 → n1 but stops at the cap, so the
        // compounded `included` set is exactly the two distinct further nodes
        // {n2, n3} (n1 is the primary, deduplicated) — the preloader neither
        // over-runs the cap nor silently skips the allowed tree.
        $response = $this->handle('/nodes/n1?include=next.next.next');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $included = $this->decode($response)['included'] ?? null;
        self::assertIsArray($included);

        $ids = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            self::assertSame('nodes', $resource['type'] ?? null);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }
        \sort($ids);
        self::assertSame(['n2', 'n3'], $ids);
    }
}
