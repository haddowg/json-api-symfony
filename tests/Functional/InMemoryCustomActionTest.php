<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Action\ActionInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see CustomActionConformanceTestCase} against the in-memory provider/persister:
 * the witness for the Doctrine action path, running the same §10 action matrix over
 * the shared {@see \haddowg\JsonApiBundle\DataProvider\InMemoryStore} with no database.
 */
final class InMemoryCustomActionTest extends CustomActionConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ActionInMemoryTestKernel::class;
    }

    #[Test]
    #[Group('spec:actions')]
    public function anAsLinkActionRendersOnAnIncludedResourceToo(): void
    {
        // The asLink contributor runs for EVERY rendered resource of the type, not only
        // the primary one (bundle ADR 0091). Widget 1's `related` to-one points at widget
        // 2, so `?include=related` renders widget 2 as an included member — which carries
        // the ungated `links.pin` member exactly as the primary resource does.
        //
        // The `related` relation is declared only on the in-memory WidgetResource (the
        // Doctrine kernel keeps the bare base), so this is in-memory-only; the
        // contribution itself is provider-agnostic — the dual-provider primary-resource
        // cases prove it on both.
        $response = $this->action('/actionWidgets/1?include=related', 'GET');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $included = $this->decode($response)['included'] ?? null;
        self::assertIsArray($included);
        self::assertCount(1, $included);

        $member = $included[0];
        self::assertIsArray($member);
        self::assertSame('actionWidgets', $member['type'] ?? null);
        self::assertSame('2', $member['id'] ?? null);

        $links = $member['links'] ?? null;
        self::assertIsArray($links);
        self::assertArrayHasKey('pin', $links);
        self::assertStringEndsWith('/actionWidgets/2/-actions/pin', $this->href($links['pin']));
    }
}
