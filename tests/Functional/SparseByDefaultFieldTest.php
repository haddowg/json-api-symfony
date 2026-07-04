<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Sparse\SparseInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The sparse-by-default field witness (core ADR 0117): the `sparseWidgets` resource's
 * `expensiveScore` attribute renders only when the client explicitly names it in a
 * `fields[sparseWidgets]` member, proving core's opt-in visibility tier flows through
 * the bundle's serializer → transformer → response stack end-to-end over HTTP.
 */
final class SparseByDefaultFieldTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return SparseInMemoryTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-sparse-fieldsets')]
    public function aSparseByDefaultFieldIsOmittedFromTheDefaultResponse(): void
    {
        $attributes = $this->attributesOf($this->handle('/sparseWidgets/1'));

        self::assertSame('Gadget', $attributes['name'] ?? null);
        self::assertArrayNotHasKey('expensiveScore', $attributes);
    }

    #[Test]
    #[Group('spec:fetching-sparse-fieldsets')]
    public function aSparseByDefaultFieldRendersWhenExplicitlyRequested(): void
    {
        $attributes = $this->attributesOf(
            $this->handle('/sparseWidgets/1?fields[sparseWidgets]=name,expensiveScore'),
        );

        self::assertSame('Gadget', $attributes['name'] ?? null);
        self::assertSame(99, $attributes['expensiveScore'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-sparse-fieldsets')]
    public function aSparseByDefaultFieldStaysAbsentWhenAnotherFieldIsRequested(): void
    {
        // Naming only `name` keeps the sparse field absent — it renders ONLY when named.
        $attributes = $this->attributesOf(
            $this->handle('/sparseWidgets/1?fields[sparseWidgets]=name'),
        );

        self::assertSame('Gadget', $attributes['name'] ?? null);
        self::assertArrayNotHasKey('expensiveScore', $attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesOf(Response $response): array
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $attributes = $data['attributes'] ?? [];
        self::assertIsArray($attributes);

        return $attributes;
    }
}
