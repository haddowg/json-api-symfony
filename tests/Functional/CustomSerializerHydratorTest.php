<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\CustomSerializerHydratorTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The custom serializer/hydrator witness (ADR 0023): the `gadget` type overrides
 * its serializer and hydrator via #[AsJsonApiResource(serializer:, hydrator:)], and
 * the generic CRUD engine drives reads through the override serializer (name
 * upper-cased + a meta marker) and writes through the override hydrator (name
 * prefixed). Both overrides carry a bound constructor dependency, so a passing test
 * also proves they were container-resolved with DI. Storage-orthogonal, so it runs
 * on the in-memory kernel only.
 */
final class CustomSerializerHydratorTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return CustomSerializerHydratorTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching')]
    public function readsRunThroughTheOverrideSerializer(): void
    {
        $response = $this->handle('/gadget/g1');
        self::assertSame(200, $response->getStatusCode());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        // The override serializer upper-cases `name`...
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('ORIGINAL', $attributes['name'] ?? null);

        // ...and emits a meta marker carrying its injected dependency.
        $meta = $data['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertSame('custom-serializer', $meta['served_by'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function writesRunThroughTheOverrideHydrator(): void
    {
        $response = $this->handle('/gadget', 'POST', [
            'data' => [
                'type' => 'gadget',
                'attributes' => ['name' => 'Widget'],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);

        // The override hydrator prefixed the name (hydrated:Widget); the override
        // serializer then upper-cased it on the way out — both ran.
        self::assertSame('HYDRATED:WIDGET', $attributes['name'] ?? null);
    }
}
