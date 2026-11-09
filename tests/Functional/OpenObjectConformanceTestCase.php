<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenObject\SheetFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The open-object conformance witness, run against both providers: an `Obj` declaring
 * `additionalProperties()` round-trips the members it does not declare, and the
 * validator bridge checks each one against the member field at its own pointer, its key
 * escaped, and each object's member count at the object.
 */
abstract class OpenObjectConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const array ROWS = [
        ['Key' => 'RFP-123', 'Vendor' => 'Acme Media', 'CPL' => '42'],
        ['Key' => 'RFP-124', 'Unit/Price' => '3', 'Cost [GBP]' => '9'],
    ];

    #[Test]
    #[Group('spec:crud')]
    public function rowsKeyedByArbitraryHeadersRoundTrip(): void
    {
        $created = $this->create(['rows' => self::ROWS, 'labels' => ['a/b' => 'x', 'c' => null]]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        self::assertSame(self::ROWS, $this->at($this->decode($created), 'data', 'attributes', 'rows'));

        $this->detachPersistedState();
        $attributes = $this->attributesOf($this->handle('/sheets/' . $this->createdId($created)));
        self::assertSame(self::ROWS, $attributes['rows'] ?? null);
        self::assertSame(['a/b' => 'x', 'c' => null], $attributes['labels'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function aPartialUpdateMergesTheMembers(): void
    {
        $response = $this->update(['labels' => ['size' => 'small', 'mood' => 'calm'], 'settings' => ['limits' => ['weekly' => 50]]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->detachPersistedState();
        $attributes = $this->attributesOf($this->handle('/sheets/1'));
        self::assertSame(['tone' => 'warm', 'size' => 'small', 'mood' => 'calm'], $attributes['labels'] ?? null);
        self::assertSame(['theme' => 'dark', 'limits' => ['daily' => 10, 'weekly' => 50]], $attributes['settings'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function anInvalidMemberPointsAtItsEscapedKey(): void
    {
        $response = $this->create(['rows' => [
            ['Key' => 'RFP-1', 'Vendor' => \str_repeat('x', 21)],
            ['Key' => 'RFP-2', 'Unit/Price' => \str_repeat('x', 21), 'a~b' => \str_repeat('x', 21), 'Cost [GBP]' => \str_repeat('x', 21)],
        ]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            ['/data/attributes/rows/0/Vendor', '/data/attributes/rows/1/Unit~1Price', '/data/attributes/rows/1/a~0b', '/data/attributes/rows/1/Cost [GBP]'],
            $this->pointersOf($response),
        );
        self::assertSame(\array_fill(0, 4, 'VALIDATION_FAILED'), $this->codesOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aNullMemberIsRefusedUnlessTheMemberFieldIsNullable(): void
    {
        $response = $this->create(['rows' => [['Key' => 'RFP-1', 'Vendor' => null]], 'labels' => ['a' => null]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['/data/attributes/rows/0/Vendor'], $this->pointersOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function memberCountsAreEnforcedAtTheObject(): void
    {
        $response = $this->create([
            'rows' => [['Key' => 'RFP-1', 'a' => '1', 'b' => '2', 'c' => '3', 'd' => '4']],
            'labels' => [],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['/data/attributes/rows/0', '/data/attributes/labels'], $this->pointersOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aDeclaredChildKeepsItsOwnRules(): void
    {
        $response = $this->update(['rows' => [['Vendor' => 'Acme']]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['/data/attributes/rows/0/Key'], $this->pointersOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function anOpenObjectNestedInAClosedOneIsValidated(): void
    {
        $response = $this->update(['settings' => ['theme' => 'light', 'limits' => ['daily' => -1]]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['/data/attributes/settings/limits/daily'], $this->pointersOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aClosedObjectStillRefusesAnUndeclaredMember(): void
    {
        $response = $this->update(['settings' => ['theme' => 'light', 'colour' => 'red']]);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['/data/attributes/settings/colour'], $this->pointersOf($response));
        self::assertSame(['ATTRIBUTE_MEMBER_UNRECOGNIZED'], $this->codesOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function readOnlyMembersAreIgnoredOnAWrite(): void
    {
        $response = $this->update(['metrics' => ['views' => 'many', 'clicks' => 9]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->detachPersistedState();
        self::assertSame(SheetFactory::SEED_METRICS, $this->attributesOf($this->handle('/sheets/1'))['metrics'] ?? null);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theOpenApiDocumentCarriesTheMemberSchemas(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $document = $factory->forServer()->toArray();

        $info = $document['info'] ?? null;
        self::assertIsArray($info);
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::OPEN_OBJECTS)], $info['x-generator'] ?? null);

        $attributes = $this->at($document, 'components', 'schemas', 'SheetsCreateAttributes', 'properties');
        self::assertSame([
            'type' => 'object',
            'maxProperties' => 4,
            'properties' => ['Key' => ['type' => 'string', 'minLength' => 1]],
            'required' => ['Key'],
            'additionalProperties' => ['type' => 'string', 'maxLength' => 20],
        ], $this->at($attributes, 'rows', 'items'));
        self::assertSame(['type' => ['object', 'null'], 'additionalProperties' => false], $this->at($attributes, 'metrics'));
        self::assertSame(['type' => 'integer', 'minimum' => 0], $this->at($attributes, 'settings', 'properties', 'limits', 'additionalProperties'));
    }

    /**
     * Detaches persisted state before a re-read, so the read decodes what the store
     * holds. The Doctrine provider overrides this to clear the identity map.
     */
    protected function detachPersistedState(): void {}

    /**
     * @param array<string, mixed> $attributes
     */
    private function create(array $attributes): Response
    {
        return $this->handle('/sheets', 'POST', [
            'data' => ['type' => 'sheets', 'attributes' => ['name' => 'Sheet', ...$attributes]],
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function update(array $attributes): Response
    {
        return $this->handle('/sheets/1', 'PATCH', [
            'data' => ['type' => 'sheets', 'id' => '1', 'attributes' => $attributes],
        ]);
    }

    private function createdId(Response $response): string
    {
        $id = $this->at($this->decode($response), 'data', 'id');
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesOf(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $attributes = $this->at($this->decode($response), 'data', 'attributes');
        self::assertIsArray($attributes);

        return $attributes;
    }

    /**
     * @return list<mixed>
     */
    private function pointersOf(Response $response): array
    {
        return \array_map(fn(mixed $error): mixed => $this->at($error, 'source', 'pointer'), $this->errorsOf($response));
    }

    /**
     * @return list<mixed>
     */
    private function codesOf(Response $response): array
    {
        return \array_map(fn(mixed $error): mixed => $this->at($error, 'code'), $this->errorsOf($response));
    }

    /**
     * @return list<mixed>
     */
    private function errorsOf(Response $response): array
    {
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);

        return \array_values($errors);
    }

    /**
     * The value at a key path through nested arrays, or `null` when a step is missing.
     */
    private function at(mixed $value, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
