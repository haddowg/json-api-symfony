<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\OpenApiDecoratorOrderingTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Ordering witness for the decorator seam (design §5, D7 — bundle ADR 0080). Two
 * decorators both overwrite the document title; the documented contract is that the
 * **highest-priority** decorator is applied last and gets the final word (the bundle's
 * highest-wins convention, consistent with providers/persisters/mappers). This proves
 * the {@see \haddowg\JsonApiBundle\OpenApi\DocumentFactory} reverses the tagged iterator
 * so application order is ascending priority.
 */
final class OpenApiDecoratorOrderingTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return OpenApiDecoratorOrderingTestKernel::class;
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theHighestPriorityDecoratorGetsTheFinalWord(): void
    {
        $document = $this->decode($this->handle('/docs.json'));

        $info = $document['info'] ?? [];
        self::assertIsArray($info);

        self::assertSame(
            OpenApiDecoratorOrderingTestKernel::HIGH_PRIORITY_TITLE,
            $info['title'] ?? null,
            'The highest-priority decorator must be applied last and win the title.',
        );
    }
}
