<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\OpenApiTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The JSON Schema serving witness (bundle ADR 0101): boots the {@see OpenApiTestKernel},
 * hits `GET /schemas.json`, and asserts the served aggregate is the per-type standalone
 * JSON Schema 2020-12 documents — one object keyed by JSON:API type, each a valid,
 * self-contained schema (`$schema` dialect + addressable `$id` + the resource object) —
 * served as `application/json`, the source a client codegen consumes for its validation
 * seam.
 */
final class JsonSchemaServingTest extends JsonApiFunctionalTestCase
{
    private const DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    protected static function getKernelClass(): string
    {
        return OpenApiTestKernel::class;
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theAggregateIsServedAsApplicationJson(): void
    {
        $response = $this->handle('/schemas.json');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theAggregateIsKeyedByTypeWithSelfContainedSchemas(): void
    {
        $aggregate = $this->fetchAggregate();

        // One entry per registered type, keyed by JSON:API type.
        self::assertArrayHasKey('products', $aggregate);
        self::assertArrayHasKey('categories', $aggregate);

        foreach (['products', 'categories'] as $type) {
            $schema = $this->nested($aggregate, $type);
            // The standalone document carries the dialect + an addressable identity, so
            // it validates on its own (not just as an in-document component).
            self::assertSame(self::DIALECT, $schema['$schema'] ?? null, $type);
            self::assertSame('urn:jsonapi:schema:' . $type, $schema['$id'] ?? null, $type);
            // It is the resource object: a `type` const pinning the JSON:API type.
            self::assertSame($type, $this->nested($schema, 'properties', 'type')['const'] ?? null, $type);
        }
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theServedAggregateMatchesTheCliExportForAType(): void
    {
        // The HTTP aggregate and the CLI export draw from the same JsonSchemaFactory, so
        // the served products schema is byte-equal to the factory's projection.
        $aggregate = $this->fetchAggregate();

        $factory = self::getContainer()->get(\haddowg\JsonApiBundle\OpenApi\JsonSchemaFactory::class);
        self::assertInstanceOf(\haddowg\JsonApiBundle\OpenApi\JsonSchemaFactory::class, $factory);

        $expected = \json_decode(
            (string) \json_encode($factory->forType('products'), \JSON_THROW_ON_ERROR),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame($expected, $this->nested($aggregate, 'products'));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theStandaloneAttributesAreTypedWhileRelationshipsStayPermissive(): void
    {
        // The standalone document agrees with the OpenAPI document on attributes (the
        // same projector), but its relationships/links/meta are the permissive
        // `{type: object}` placeholders — the OpenAPI document narrows those via
        // components a self-contained schema cannot reference (bundle ADR 0106).
        $products = $this->nested($this->fetchAggregate(), 'products');

        // Attributes: a concrete, typed inline object (specific — has properties).
        $attributes = $this->nested($products, 'properties', 'attributes');
        self::assertSame('object', $attributes['type'] ?? null);
        self::assertArrayHasKey('properties', $attributes);

        // Relationships: the permissive placeholder — a bare object, no per-relation
        // narrowing in the standalone form.
        self::assertSame(['type' => 'object'], $this->nested($products, 'properties', 'relationships'));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchAggregate(): array
    {
        $response = $this->handle('/schemas.json');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $decoded = \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function nested(array $data, string ...$keys): array
    {
        $current = $data;
        foreach ($keys as $key) {
            self::assertIsArray($current);
            self::assertArrayHasKey($key, $current);
            $current = $current[$key];
        }

        self::assertIsArray($current);

        return $current;
    }
}
