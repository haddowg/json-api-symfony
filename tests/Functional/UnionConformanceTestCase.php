<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The union witness, run against both providers: a value's JSON type selects the member
 * whose cast, rules and stored PHP type apply, at the union's own pointer, at the top
 * level, inside an object and as a list's elements.
 */
abstract class UnionConformanceTestCase extends JsonApiFunctionalTestCase
{
    /**
     * What the store holds for a property of the setting with the id.
     */
    abstract protected function stored(string $id, string $property): mixed;

    /**
     * Whether the store keeps a top-level union date as a string, as a `json` column must.
     */
    abstract protected function holdsTopLevelDatesAsStrings(): bool;

    /**
     * Detaches persisted state before a re-read, so the read decodes what the store
     * holds. The Doctrine provider overrides this to clear the identity map.
     */
    protected function detachPersistedState(): void {}

    #[Test]
    #[Group('spec:crud')]
    public function eachMemberRoundTrips(): void
    {
        $cases = [
            'a string' => ['value', 'gold', 'gold'],
            'a decimal' => ['value', 2.5, 2.5],
            'true' => ['value', true, true],
            'false' => ['value', false, false],
            'a list of mixed elements' => ['value', ['gold', 2.5, false], ['gold', 2.5, false]],
            'an enum string' => ['threshold', 'auto', 'auto'],
            'an integer' => ['threshold', 5, 5],
            'a date' => ['startsOn', '2026-01-02', '2026-01-02'],
            'a boolean beside a date' => ['startsOn', true, true],
            'a list of dates and integers' => ['marks', ['2026-01-02', 7], ['2026-01-02', 7]],
            'a union inside an object' => ['window', ['until' => '2026-03-04', 'cap' => 'abc'], ['until' => '2026-03-04', 'cap' => 'abc']],
            'an integer inside an object' => ['window', ['until' => false, 'cap' => 10], ['until' => false, 'cap' => 10]],
        ];

        foreach ($cases as $case => [$name, $sent, $expected]) {
            $response = $this->create([$name => $sent]);

            self::assertSame(201, $response->getStatusCode(), $case . ': ' . $response->getContent());
            self::assertSame($expected, $this->at($this->decode($response), 'data', 'attributes', $name), $case);

            $id = $this->at($this->decode($response), 'data', 'id');
            self::assertIsString($id);
            $this->detachPersistedState();
            $read = $this->handle('/settings/' . $id);
            self::assertSame(200, $read->getStatusCode(), $case . ': ' . $read->getContent());
            self::assertSame($expected, $this->at($this->decode($read), 'data', 'attributes', $name), $case . ' read back');
        }
    }

    #[Test]
    #[Group('spec:crud')]
    public function eachRequiredAnswerKindRoundTrips(): void
    {
        foreach (['yes', 0, 42, false, true, ['a', 1]] as $answer) {
            $response = $this->create(['answer' => $answer]);

            self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
            $id = $this->at($this->decode($response), 'data', 'id');
            self::assertIsString($id);
            $this->detachPersistedState();
            self::assertSame($answer, $this->at($this->decode($this->handle('/settings/' . $id)), 'data', 'attributes', 'answer'));
        }
    }

    #[Test]
    #[Group('spec:crud')]
    public function aDateMemberIsStoredInAFormItsStoreHolds(): void
    {
        $response = $this->create([
            'startsOn' => '2026-01-02',
            'window' => ['until' => '2026-03-04', 'cap' => 1],
            'marks' => ['2026-05-06', 2],
            'value' => 3,
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $id = $this->at($this->decode($response), 'data', 'id');
        self::assertIsString($id);
        $this->detachPersistedState();

        $startsOn = $this->stored($id, 'startsOn');
        if ($this->holdsTopLevelDatesAsStrings()) {
            self::assertSame('2026-01-02', $startsOn);
        } else {
            self::assertInstanceOf(\DateTimeImmutable::class, $startsOn);
            self::assertSame('2026-01-02', $startsOn->format('Y-m-d'));
        }
        self::assertSame(['until' => '2026-03-04', 'cap' => 1], $this->stored($id, 'window'));
        self::assertSame(['2026-05-06', 2], $this->stored($id, 'marks'));
        self::assertSame(3.0, $this->stored($id, 'value'));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aMembersRulesApplyAtTheUnionsPointer(): void
    {
        $payloads = [
            'a string over the string member maxLength' => [['value' => 'much too long'], ['/data/attributes/value']],
            'a list over the list member maxItems' => [['value' => ['a', 1, true, false]], ['/data/attributes/value']],
            'a list element over its own member maxLength' => [['value' => ['a', 'much too long', 1]], ['/data/attributes/value/1']],
            'a string outside the enum member' => [['threshold' => 'sometimes'], ['/data/attributes/threshold']],
            'a number under the integer member minimum' => [['threshold' => -1], ['/data/attributes/threshold']],
            'a string inside an object over its maxLength' => [['window' => ['cap' => 'much too long']], ['/data/attributes/window/cap']],
            'a number inside an object over its maximum' => [['window' => ['cap' => 11]], ['/data/attributes/window/cap']],
            'an impossible date' => [['startsOn' => '2026-02-30'], ['/data/attributes/startsOn']],
            'an impossible date in a list' => [['marks' => [1, '2026-02-30']], ['/data/attributes/marks/1']],
        ];

        foreach ($payloads as $case => [$attributes, $pointers]) {
            $response = $this->create($attributes);

            self::assertSame(422, $response->getStatusCode(), $case . ': ' . $response->getContent());
            self::assertSame($pointers, $this->pointersOf($response), $case);
        }
    }

    #[Test]
    #[Group('spec:crud')]
    public function aValueNoMemberTakesIsATypeMismatch(): void
    {
        $payloads = [
            'an object' => [['value' => ['a' => 1]], '/data/attributes/value', 'string|number|boolean|array'],
            'a fraction for an integer member' => [['threshold' => 1.5], '/data/attributes/threshold', 'string|integer'],
            'an object list element' => [['value' => ['a', ['b' => 1]]], '/data/attributes/value/1', 'string|number|boolean'],
            'a boolean in a list of dates and integers' => [['marks' => [true]], '/data/attributes/marks/0', 'string|integer'],
            'a list where no list member is declared' => [['threshold' => ['auto']], '/data/attributes/threshold', 'string|integer'],
        ];

        foreach ($payloads as $case => [$attributes, $pointer, $type]) {
            $response = $this->create($attributes);

            self::assertSame(422, $response->getStatusCode(), $case . ': ' . $response->getContent());
            self::assertSame([$pointer], $this->pointersOf($response), $case);
            self::assertSame(['This value should be of type ' . $type . '.'], $this->detailsOf($response), $case);
        }
    }

    #[Test]
    #[Group('spec:crud')]
    public function aRequiredUnionRefusesAnEmptyStringOrListButTakesFalseAndZero(): void
    {
        foreach (['an empty string' => '', 'an empty list' => []] as $case => $answer) {
            $response = $this->create(['answer' => $answer]);

            self::assertSame(422, $response->getStatusCode(), $case . ': ' . $response->getContent());
            self::assertSame(['/data/attributes/answer'], $this->pointersOf($response), $case);
        }

        foreach (['false' => false, 'zero' => 0] as $case => $answer) {
            self::assertSame(201, $this->create(['answer' => $answer])->getStatusCode(), $case);
        }

        $absent = $this->handle('/settings', 'POST', ['data' => ['type' => 'settings', 'attributes' => ['value' => 'gold', 'currency' => 'GBP']]]);
        self::assertSame(422, $absent->getStatusCode(), (string) $absent->getContent());
        self::assertSame(['/data/attributes/answer'], $this->pointersOf($absent));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aRequiredFieldOfTheApplicationsOwnSchemaRefusesAnEmptyValue(): void
    {
        $response = $this->create(['currency' => '']);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['/data/attributes/currency'], $this->pointersOf($response));
        self::assertSame(['This value should not be blank.'], $this->detailsOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function anUpdateKeepsTheNonEmptyRuleAndStoresEachMember(): void
    {
        $response = $this->handle('/settings/1', 'PATCH', [
            'data' => ['type' => 'settings', 'id' => '1', 'attributes' => ['answer' => '']],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['/data/attributes/answer'], $this->pointersOf($response));

        $response = $this->handle('/settings/1', 'PATCH', [
            'data' => ['type' => 'settings', 'id' => '1', 'attributes' => ['answer' => false, 'value' => 'gold', 'startsOn' => '2026-07-08']],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertFalse($this->at($this->decode($response), 'data', 'attributes', 'answer'));
        self::assertSame('gold', $this->at($this->decode($response), 'data', 'attributes', 'value'));

        $this->detachPersistedState();
        $read = $this->decode($this->handle('/settings/1'));
        self::assertFalse($this->at($read, 'data', 'attributes', 'answer'));
        self::assertSame('2026-07-08', $this->at($read, 'data', 'attributes', 'startsOn'));
    }

    #[Test]
    #[Group('spec:crud')]
    public function nullPassesOnlyANullableUnion(): void
    {
        $response = $this->create(['value' => null, 'threshold' => null, 'startsOn' => null]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertNull($this->at($this->decode($response), 'data', 'attributes', 'value'));

        $response = $this->create(['answer' => null]);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['/data/attributes/answer'], $this->pointersOf($response));

        $response = $this->create(['window' => ['cap' => null]]);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['/data/attributes/window/cap'], $this->pointersOf($response));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theOpenApiDocumentProjectsBothUnionForms(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $attributes = $this->at($factory->forServer()->toArray(), 'components', 'schemas', 'SettingsCreateAttributes', 'properties');

        self::assertSame([
            'type' => ['string', 'number', 'boolean', 'array', 'null'],
            'maxLength' => 10,
            'maxItems' => 3,
            'items' => ['type' => ['string', 'number', 'boolean'], 'maxLength' => 5],
        ], $this->at($attributes, 'value'));
        self::assertSame([
            'type' => ['string', 'integer', 'boolean', 'array'],
            'minLength' => 1,
            'minItems' => 1,
            'items' => ['type' => ['string', 'integer']],
        ], $this->at($attributes, 'answer'));
        self::assertSame([
            'oneOf' => [
                ['type' => 'string', 'enum' => ['auto', 'off']],
                ['type' => 'integer', 'minimum' => 0],
                ['type' => 'null'],
            ],
        ], $this->at($attributes, 'threshold'));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function create(array $attributes): Response
    {
        return $this->handle('/settings', 'POST', [
            'data' => ['type' => 'settings', 'attributes' => ['answer' => 'yes', 'currency' => 'GBP', ...$attributes]],
        ]);
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
    private function detailsOf(Response $response): array
    {
        return \array_map(fn(mixed $error): mixed => $this->at($error, 'detail'), $this->errorsOf($response));
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
