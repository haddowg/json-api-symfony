<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Composite\PipelineFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The structured-list conformance witness, run against both providers: an
 * `ArrayList` of `OneOf` elements (`steps`) and one of `Obj` elements (`stops`)
 * round-trip in order, every write replaces the whole list, and the validator
 * bridge checks each element at `/data/attributes/<list>/<index>`. The structural
 * problems core's hydration rejects surface as its `ATTRIBUTE_VALUE_INVALID`.
 */
abstract class StructuredListConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const array STEPS = [
        ['operation' => 'find_replace', 'find' => 'colour', 'replace' => 'color'],
        ['operation' => 'trim'],
        ['operation' => 'find_replace', 'find' => 'grey', 'replace' => 'gray'],
    ];

    private const array STOPS = [
        ['city' => 'Paris', 'code' => 'PAR', 'nights' => 2, 'legs' => [['mode' => 'rail'], ['mode' => 'road']]],
        ['city' => 'Lyon', 'code' => 'LYS', 'nights' => 1, 'legs' => []],
    ];

    #[Test]
    #[Group('spec:crud')]
    public function structuredListsRoundTripInOrder(): void
    {
        $created = $this->create(['steps' => self::STEPS, 'stops' => self::STOPS]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());

        $attributes = $this->attributesOf($this->handle('/pipelines/' . $this->createdId($created)));
        self::assertSame(self::STEPS, $attributes['steps'] ?? null);
        self::assertSame(self::readBack(self::STOPS), $attributes['stops'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function anUpdateReplacesTheWholeList(): void
    {
        $steps = [['operation' => 'find_replace', 'find' => 'a', 'replace' => 'b']];
        $stops = [['city' => 'Leeds', 'code' => 'LBA', 'nights' => 3, 'legs' => []]];

        $response = $this->update(['steps' => $steps, 'stops' => $stops]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $attributes = $this->attributesOf($this->handle('/pipelines/1'));
        self::assertSame($steps, $attributes['steps'] ?? null);
        // The stored element's `nights` is not carried into the new one.
        self::assertSame(self::readBack($stops), $attributes['stops'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function anEmptyListStoresAnEmptyList(): void
    {
        $response = $this->update(['steps' => []]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame([], $this->attributesOf($this->handle('/pipelines/1'))['steps'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function aNullClearsTheList(): void
    {
        $response = $this->update(['stops' => null]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $attributes = $this->attributesOf($this->handle('/pipelines/1'));
        self::assertArrayHasKey('stops', $attributes);
        self::assertNull($attributes['stops']);
    }

    #[Test]
    #[Group('spec:crud')]
    public function anUpdateLeavingTheListsOutKeepsThem(): void
    {
        $response = $this->update(['name' => 'Renamed']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $attributes = $this->attributesOf($this->handle('/pipelines/1'));
        self::assertSame(PipelineFactory::SEED_STEPS, $attributes['steps'] ?? null);
        self::assertSame('LON', $this->at($attributes, 'stops', 0, 'code'));
    }

    #[Test]
    #[Group('spec:crud')]
    public function anInvalidElementChildPointsAtTheElement(): void
    {
        $response = $this->create(['stops' => [
            self::STOPS[0],
            ['city' => '', 'code' => 'LYS', 'nights' => 0, 'arrives' => '2019-12-31'],
        ]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            ['/data/attributes/stops/1/city', '/data/attributes/stops/1/nights', '/data/attributes/stops/1/arrives'],
            $this->pointersOf($response),
        );
    }

    #[Test]
    #[Group('spec:crud')]
    public function aVariantChildPointsAtTheElement(): void
    {
        $response = $this->create(['steps' => [
            ['operation' => 'trim'],
            ['operation' => 'find_replace', 'find' => 'a', 'replace' => 'far too long'],
        ]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['/data/attributes/steps/1/replace'], $this->pointersOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aNestedListElementPointsThroughBothIndexes(): void
    {
        $response = $this->create(['stops' => [
            ['city' => 'Paris', 'code' => 'PAR', 'legs' => [['mode' => 'rail'], ['mode' => 'air']]],
        ]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['/data/attributes/stops/0/legs/1/mode'], $this->pointersOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function anUnknownDiscriminatorPointsAtTheElementDiscriminator(): void
    {
        $response = $this->create(['steps' => [['operation' => 'trim'], ['operation' => 'explode']]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['/data/attributes/steps/1/operation'], $this->pointersOf($response));
        self::assertSame(['VALIDATION_FAILED'], $this->codesOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aMissingDiscriminatorPointsAtTheElementDiscriminator(): void
    {
        $response = $this->create(['steps' => [['find' => 'a', 'replace' => 'b']]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['/data/attributes/steps/0/operation'], $this->pointersOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aScalarOrNullElementPointsAtTheElement(): void
    {
        $response = $this->create(['steps' => ['trim', ['operation' => 'trim']], 'stops' => [null]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['/data/attributes/steps/0', '/data/attributes/stops/0'], $this->pointersOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aListOverItsMaximumPointsAtTheList(): void
    {
        $response = $this->create(['steps' => \array_fill(0, 4, ['operation' => 'trim'])]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['/data/attributes/steps'], $this->pointersOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aCustomConstraintOnAnElementChildIsEnforced(): void
    {
        $response = $this->create(['stops' => [['city' => 'Paris', 'code' => 'par']]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([[
            'status' => '422',
            'code' => 'VALIDATION_FAILED',
            'title' => 'Unprocessable Entity',
            'detail' => 'This value is not a valid stop code.',
            'source' => ['pointer' => '/data/attributes/stops/0/code'],
        ]], $this->decode($response)['errors'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function aRequiredElementChildIsEnforcedOnUpdate(): void
    {
        $response = $this->update([
            'steps' => [['operation' => 'find_replace', 'find' => 'a']],
            'stops' => [['city' => 'Leeds']],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['/data/attributes/steps/0/replace', '/data/attributes/stops/0/code'], $this->pointersOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function anObjectSentForTheListIsCoreStructuralError(): void
    {
        $response = $this->create(['steps' => ['first' => ['operation' => 'trim']]]);

        $this->assertStructuralError($response, '/data/attributes/steps');
    }

    #[Test]
    #[Group('spec:crud')]
    public function aListSentForAnElementIsCoreStructuralError(): void
    {
        $response = $this->create(['stops' => [self::STOPS[0], ['Paris', 'PAR']]]);

        $this->assertStructuralError($response, '/data/attributes/stops/1');
    }

    #[Test]
    #[Group('spec:crud')]
    public function aListSentForANestedElementIsCoreStructuralError(): void
    {
        $response = $this->update(['stops' => [['city' => 'Paris', 'code' => 'PAR', 'legs' => [['rail']]]]]);

        $this->assertStructuralError($response, '/data/attributes/stops/0/legs/0');
    }

    #[Test]
    #[Group('spec:crud')]
    public function anUnparseableElementDateIsCoreStructuralError(): void
    {
        $response = $this->create(['stops' => [['city' => 'Paris', 'code' => 'PAR', 'arrives' => 'banana']]]);

        $this->assertStructuralError($response, '/data/attributes/stops/0/arrives');
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theOpenApiDocumentCarriesTheElementSchemas(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $document = $factory->forServer()->toArray();

        $info = $document['info'] ?? null;
        self::assertIsArray($info);
        self::assertSame(['contract' => GeneratorContract::STRUCTURED_LIST_ELEMENTS], $info['x-generator'] ?? null);

        $attributes = $this->at($document, 'components', 'schemas', 'PipelinesUpdateAttributes', 'properties');

        self::assertSame(3, $this->at($attributes, 'steps', 'maxItems'));
        self::assertSame(['propertyName' => 'operation'], $this->at($attributes, 'steps', 'items', 'discriminator'));
        $variants = $this->at($attributes, 'steps', 'items', 'oneOf');
        self::assertIsArray($variants);
        self::assertCount(2, $variants);

        // An update builds each element whole, so it requires what a create does.
        self::assertSame('object', $this->at($attributes, 'stops', 'items', 'type'));
        self::assertSame(['city', 'code'], $this->at($attributes, 'stops', 'items', 'required'));
        $properties = $this->at($attributes, 'stops', 'items', 'properties');
        self::assertIsArray($properties);
        self::assertSame(['city', 'code', 'nights', 'arrives', 'legs'], \array_keys($properties));
    }

    /**
     * The stops as a read renders them: every declared child, an unsent one as `null`.
     *
     * @param list<array<string, mixed>> $stops
     *
     * @return list<array<string, mixed>>
     */
    private static function readBack(array $stops): array
    {
        return \array_map(static fn(array $stop): array => [
            'city' => $stop['city'],
            'code' => $stop['code'],
            'nights' => $stop['nights'] ?? null,
            'arrives' => $stop['arrives'] ?? null,
            'legs' => $stop['legs'] ?? null,
        ], $stops);
    }

    private function assertStructuralError(Response $response, string $pointer): void
    {
        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$pointer], $this->pointersOf($response));
        self::assertSame(['ATTRIBUTE_VALUE_INVALID'], $this->codesOf($response));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function create(array $attributes): Response
    {
        return $this->handle('/pipelines', 'POST', [
            'data' => ['type' => 'pipelines', 'attributes' => ['name' => 'Pipeline', ...$attributes]],
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function update(array $attributes): Response
    {
        return $this->handle('/pipelines/1', 'PATCH', [
            'data' => ['type' => 'pipelines', 'id' => '1', 'attributes' => $attributes],
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
