<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The JSON type witness, run against both providers: a written value whose JSON type is
 * not the one the write schema documents for its field is a `422` at its pointer, the
 * only error there, instead of being cast. It holds at every depth, and `null` passes
 * only where the field is nullable.
 */
abstract class ValueTypeConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    #[Group('spec:crud')]
    public function wellTypedValuesAreStored(): void
    {
        $response = $this->create([
            'count' => 3,
            'ratio' => 2.5,
            'flag' => false,
            'day' => '2026-01-02',
            'at' => '2026-01-02T03:04:05+00:00',
            'kind' => 'draft',
            'tags' => ['a', 'b'],
            'options' => ['notifyPublishers' => true, 'note' => null],
            'rows' => [['Key' => 'RFP-1', 'Vendor' => 'Acme']],
            'mapping' => ['entries' => [['target' => ['name' => 'Leads']]]],
            'block' => ['kind' => 'heading', 'text' => 'Hi', 'level' => 2],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $attributes = $this->at($this->decode($response), 'data', 'attributes');
        self::assertIsArray($attributes);
        self::assertSame(3, $attributes['count'] ?? null);
        self::assertSame(2.5, $attributes['ratio'] ?? null);
        self::assertFalse($attributes['flag'] ?? null);
        self::assertSame(['notifyPublishers' => true, 'note' => null], $attributes['options'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function nullIsStoredWhereTheFieldIsNullable(): void
    {
        $response = $this->create([
            'count' => null,
            'ratio' => null,
            'flag' => null,
            'day' => null,
            'at' => null,
            'kind' => null,
            'tags' => null,
            'options' => null,
            'rows' => null,
            'mapping' => null,
            'block' => null,
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:crud')]
    public function theReportedPayloadsAreRefusedAtTheirPointers(): void
    {
        $payloads = [
            'notifyPublishers "yes"' => [['options' => ['notifyPublishers' => 'yes']], '/data/attributes/options/notifyPublishers'],
            'notifyPublishers 1' => [['options' => ['notifyPublishers' => 1]], '/data/attributes/options/notifyPublishers'],
            'notifyPublishers "false"' => [['options' => ['notifyPublishers' => 'false']], '/data/attributes/options/notifyPublishers'],
            'row Key 42' => [['rows' => [['Key' => 42]]], '/data/attributes/rows/0/Key'],
            'row Key true' => [['rows' => [['Key' => true]]], '/data/attributes/rows/0/Key'],
            'row Key a list of strings' => [['rows' => [['Key' => ['a', 'b']]]], '/data/attributes/rows/0/Key'],
            'open member 42' => [['rows' => [['Key' => 'RFP-1', 'Vendor' => 42]]], '/data/attributes/rows/0/Vendor'],
            'open member true' => [['rows' => [['Key' => 'RFP-1', 'Vendor' => true]]], '/data/attributes/rows/0/Vendor'],
            'mapping target name 42' => [['mapping' => ['entries' => [['target' => ['name' => 42]]]]], '/data/attributes/mapping/entries/0/target/name'],
        ];

        foreach ($payloads as $case => [$attributes, $pointer]) {
            $response = $this->create($attributes);

            self::assertSame(422, $response->getStatusCode(), $case . ': ' . $response->getContent());
            self::assertSame([$pointer], $this->pointersOf($response), $case);
            self::assertSame(['VALIDATION_FAILED'], $this->codesOf($response), $case);
        }
    }

    #[Test]
    #[Group('spec:crud')]
    public function theDetailNamesTheExpectedType(): void
    {
        $response = $this->create(['options' => ['notifyPublishers' => 'yes'], 'rows' => [['Key' => 42]]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            ['This value should be of type boolean.', 'This value should be of type string.'],
            \array_map(fn(mixed $error): mixed => $this->at($error, 'detail'), $this->errorsOf($response)),
        );
    }

    #[Test]
    #[Group('spec:crud')]
    public function everyTopLevelKindRefusesTheWrongType(): void
    {
        $response = $this->create([
            'name' => 42,
            'count' => '1',
            'ratio' => '1.5',
            'flag' => 'true',
            'day' => 20260102,
            'at' => 0,
            'kind' => 1,
            'tags' => 'a,b',
            'options' => 'all',
            'block' => true,
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([
            '/data/attributes/name',
            '/data/attributes/count',
            '/data/attributes/ratio',
            '/data/attributes/flag',
            '/data/attributes/day',
            '/data/attributes/at',
            '/data/attributes/kind',
            '/data/attributes/tags',
            '/data/attributes/options',
            '/data/attributes/block',
        ], $this->sortedPointersOf($response, ['name', 'count', 'ratio', 'flag', 'day', 'at', 'kind', 'tags', 'options', 'block']));
        self::assertSame(\array_fill(0, 10, 'VALIDATION_FAILED'), $this->codesOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aValueOutOfItsBoundsAndOfTheWrongTypeGetsOneError(): void
    {
        $response = $this->create(['name' => 123456789012345678901234567890.0, 'count' => '-1', 'tags' => \array_fill(0, 6, 1)]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            ['/data/attributes/name', '/data/attributes/count', '/data/attributes/tags', '/data/attributes/tags/0', '/data/attributes/tags/1', '/data/attributes/tags/2', '/data/attributes/tags/3', '/data/attributes/tags/4', '/data/attributes/tags/5'],
            $this->sortedPointersOf($response, ['name', 'count', 'tags']),
        );
    }

    #[Test]
    #[Group('spec:crud')]
    public function aListElementAndAVariantChildRefuseTheWrongType(): void
    {
        $response = $this->create([
            'tags' => ['a', 2, null],
            'rows' => ['RFP-1'],
            'block' => ['kind' => 'heading', 'text' => 'Hi', 'level' => '2'],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            ['/data/attributes/tags/1', '/data/attributes/tags/2', '/data/attributes/rows/0', '/data/attributes/block/level'],
            $this->sortedPointersOf($response, ['tags', 'rows', 'block']),
        );
    }

    #[Test]
    #[Group('spec:crud')]
    public function nullIsRefusedWhereTheFieldIsNotNullable(): void
    {
        $response = $this->create([
            'options' => ['notifyPublishers' => null],
            'rows' => [['Key' => 'RFP-1', 'Vendor' => null]],
            'mapping' => ['entries' => [['target' => ['name' => null]]]],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            ['/data/attributes/options/notifyPublishers', '/data/attributes/rows/0/Vendor', '/data/attributes/mapping/entries/0/target/name'],
            $this->sortedPointersOf($response, ['options', 'rows', 'mapping']),
        );
    }

    #[Test]
    #[Group('spec:crud')]
    public function anUpdateRefusesTheWrongType(): void
    {
        $response = $this->handle('/submissions/1', 'PATCH', [
            'data' => ['type' => 'submissions', 'id' => '1', 'attributes' => ['flag' => 1, 'options' => ['notifyPublishers' => 'no']]],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['/data/attributes/flag', '/data/attributes/options/notifyPublishers'], $this->pointersOf($response));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function create(array $attributes): Response
    {
        return $this->handle('/submissions', 'POST', [
            'data' => ['type' => 'submissions', 'attributes' => ['name' => 'Submission', ...$attributes]],
        ]);
    }

    /**
     * The error pointers, grouped by the attribute they fall under in `$order`, so a
     * check does not depend on the order the validator's passes report in.
     *
     * @param list<string> $order
     *
     * @return list<mixed>
     */
    private function sortedPointersOf(Response $response, array $order): array
    {
        $pointers = $this->pointersOf($response);
        $rank = static function (mixed $pointer) use ($order): int {
            foreach ($order as $index => $name) {
                if (\is_string($pointer) && ($pointer === '/data/attributes/' . $name || \str_starts_with($pointer, '/data/attributes/' . $name . '/'))) {
                    return $index;
                }
            }

            return \count($order);
        };
        \usort($pointers, static fn(mixed $a, mixed $b): int => [$rank($a), $a] <=> [$rank($b), $b]);

        return $pointers;
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
