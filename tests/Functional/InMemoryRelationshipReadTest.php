<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\JsonApiTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see RelationshipReadConformanceTestCase} against the in-memory provider —
 * the conformance witness half of the dual-provider relationship-read contract.
 *
 * The in-memory `articles` resource also declares an extra `editor` relation
 * that opts out of the convention links via
 * {@see \haddowg\JsonApi\Resource\Field\AbstractRelation::withoutLinks()}, so
 * this subclass additionally witnesses the opt-out: data still renders, no
 * `links` member appears.
 */
final class InMemoryRelationshipReadTest extends RelationshipReadConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return JsonApiTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelationshipDeclaringWithoutLinksOmitsLinksButStillRendersData(): void
    {
        $response = $this->handle('/articles/1');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);

        // `editor` is backed by the same `author` property, so its data renders
        // the author identifier — but it declared ->withoutLinks(), so no links.
        $editor = $relationships['editor'] ?? null;
        self::assertIsArray($editor);
        self::assertSame(['type' => 'authors', 'id' => 'a1'], $editor['data'] ?? null);
        self::assertArrayNotHasKey('links', $editor);
    }
}
