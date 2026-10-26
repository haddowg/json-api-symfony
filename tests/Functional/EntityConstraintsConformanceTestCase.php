<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * A resource's NativeEntityConstraints run against the hydrated entity with no
 * translator, and each violation is a 422 pointing at the resource or the property
 * it names.
 */
abstract class EntityConstraintsConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    public function aClassLevelViolationPointsAtTheResource(): void
    {
        $response = $this->create(['name' => 'Forbidden']);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([['/data', 'This brand is not allowed.']], $this->pointers($response));
    }

    #[Test]
    public function aViolationAtAPathPointsAtThatAttribute(): void
    {
        $response = $this->create(['name' => 'Acme', 'label' => 'reserved']);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([['/data/attributes/label', 'This label is reserved.']], $this->pointers($response));
    }

    #[Test]
    public function aValidBrandIsCreated(): void
    {
        self::assertSame(201, $this->create(['name' => 'Acme'])->getStatusCode());
    }

    #[Test]
    public function theConstraintsContextLimitsItToCreate(): void
    {
        $created = $this->create(['name' => 'Acme']);
        self::assertSame(201, $created->getStatusCode());
        $data = $this->decode($created)['data'] ?? null;
        self::assertIsArray($data);
        $id = $data['id'] ?? null;
        self::assertIsString($id);

        $response = $this->handle('/brands/' . $id, 'PATCH', ['data' => ['type' => 'brands', 'id' => $id, 'attributes' => ['name' => 'Forbidden']]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function create(array $attributes): Response
    {
        return $this->handle('/brands', 'POST', ['data' => ['type' => 'brands', 'attributes' => $attributes]]);
    }

    /**
     * @return list<array{0: mixed, 1: mixed}>
     */
    protected function pointers(Response $response): array
    {
        $errors = $this->decode($response)['errors'] ?? [];
        self::assertIsArray($errors);

        $pointers = [];
        foreach ($errors as $error) {
            self::assertIsArray($error);
            $source = $error['source'] ?? [];
            self::assertIsArray($source);
            $pointers[] = [$source['pointer'] ?? null, $error['detail'] ?? null];
        }

        return $pointers;
    }
}
