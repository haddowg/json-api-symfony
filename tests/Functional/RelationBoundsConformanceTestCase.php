<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A write that supplies a to-many's whole member set must respect the relation's
 * minItems() and maxItems(). Squads field one or two players; squad 1 fields player 1.
 */
abstract class RelationBoundsConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string TOO_FEW = 'This collection should contain 1 element or more.';
    private const string TOO_MANY = 'This collection should contain 2 elements or less.';

    #[Test]
    public function creatingWithTooFewMembersIsRefused(): void
    {
        $response = $this->handle('/squads', 'POST', $this->squad('9', []));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::TOO_FEW, '/data/relationships/players/data')], $this->decode($response)['errors'] ?? null);
        self::assertSame(404, $this->handle('/squads/9')->getStatusCode());
    }

    #[Test]
    public function creatingWithTooManyMembersIsRefused(): void
    {
        $response = $this->handle('/squads', 'POST', $this->squad('9', ['1', '2', '3']));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::TOO_MANY, '/data/relationships/players/data')], $this->decode($response)['errors'] ?? null);
        self::assertSame(404, $this->handle('/squads/9')->getStatusCode());
    }

    #[Test]
    public function creatingWithinTheBoundsIsSaved(): void
    {
        $response = $this->handle('/squads', 'POST', $this->squad('9', ['1', '2']));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['1', '2'], $this->playersOf('9'));
    }

    #[Test]
    public function aRepeatedMemberCountsOnce(): void
    {
        $response = $this->handle('/squads', 'POST', $this->squad('9', ['1', '2', '1']));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['1', '2'], $this->playersOf('9'));
    }

    #[Test]
    public function creatingWithoutTheRelationshipIsNotChecked(): void
    {
        $response = $this->handle('/squads', 'POST', ['data' => ['type' => 'squads', 'id' => '9', 'attributes' => ['name' => 'Blues']]]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    public function updatingWithTooFewMembersIsRefused(): void
    {
        $response = $this->handle('/squads/1', 'PATCH', $this->squad('1', []));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::TOO_FEW, '/data/relationships/players/data')], $this->decode($response)['errors'] ?? null);
        self::assertSame(['1'], $this->playersOf('1'));
    }

    #[Test]
    public function updatingWithTooManyMembersIsRefused(): void
    {
        $response = $this->handle('/squads/1', 'PATCH', $this->squad('1', ['1', '2', '3']));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::TOO_MANY, '/data/relationships/players/data')], $this->decode($response)['errors'] ?? null);
        self::assertSame(['1'], $this->playersOf('1'));
    }

    #[Test]
    public function updatingAttributesAloneIsNotChecked(): void
    {
        $response = $this->handle('/squads/1', 'PATCH', ['data' => ['type' => 'squads', 'id' => '1', 'attributes' => ['name' => 'Blues']]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    public function replacingWithTooFewMembersIsRefused(): void
    {
        $response = $this->handle('/squads/1/relationships/players', 'PATCH', ['data' => []]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            '{"errors":[{"status":"422","code":"VALIDATION_FAILED","title":"Unprocessable Entity","detail":"This collection should contain 1 element or more.","source":{"pointer":"\/data"}}],"jsonapi":{"version":"1.1"}}',
            $response->getContent(),
        );
        self::assertSame(['1'], $this->playersOf('1'));
    }

    #[Test]
    public function replacingWithTooManyMembersIsRefused(): void
    {
        $response = $this->handle('/squads/1/relationships/players', 'PATCH', ['data' => $this->identifiers(['1', '2', '3'])]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::TOO_MANY, '/data')], $this->decode($response)['errors'] ?? null);
        self::assertSame(['1'], $this->playersOf('1'));
    }

    #[Test]
    public function replacingWithinTheBoundsIsSaved(): void
    {
        $response = $this->handle('/squads/1/relationships/players', 'PATCH', ['data' => $this->identifiers(['2', '3'])]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['2', '3'], $this->playersOf('1'));
    }

    #[Test]
    public function anAtomicResourceUpdateIsChecked(): void
    {
        $response = $this->atomic([
            ['op' => 'update', 'ref' => ['type' => 'squads', 'id' => '1'], 'data' => ['type' => 'squads', 'id' => '1', 'attributes' => ['name' => 'Blues']]],
            ['op' => 'update', 'ref' => ['type' => 'squads', 'id' => '1'], 'data' => $this->squad('1', ['1', '2', '3'])['data']],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::TOO_MANY, '/atomic:operations/1/data/relationships/players/data')], $this->decode($response)['errors'] ?? null);
        self::assertSame(['1'], $this->playersOf('1'));
    }

    #[Test]
    public function anAtomicRelationshipReplaceIsChecked(): void
    {
        $response = $this->atomic([
            ['op' => 'update', 'ref' => ['type' => 'squads', 'id' => '1', 'relationship' => 'players'], 'data' => $this->identifiers(['2'])],
            ['op' => 'update', 'ref' => ['type' => 'squads', 'id' => '1', 'relationship' => 'players'], 'data' => []],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::TOO_FEW, '/atomic:operations/1/data')], $this->decode($response)['errors'] ?? null);
        self::assertSame(['1'], $this->playersOf('1'));
    }

    #[Test]
    public function anAtomicBatchWithinTheBoundsCommits(): void
    {
        $response = $this->atomic([
            ['op' => 'add', 'data' => $this->squad('9', ['3'])['data']],
            ['op' => 'update', 'ref' => ['type' => 'squads', 'id' => '1', 'relationship' => 'players'], 'data' => $this->identifiers(['1', '2'])],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['3'], $this->playersOf('9'));
        self::assertSame(['1', '2'], $this->playersOf('1'));
    }

    /**
     * @param list<string> $players
     *
     * @return array{data: array<string, mixed>}
     */
    private function squad(string $id, array $players): array
    {
        return ['data' => [
            'type' => 'squads',
            'id' => $id,
            'attributes' => ['name' => 'Blues'],
            'relationships' => ['players' => ['data' => $this->identifiers($players)]],
        ]];
    }

    /**
     * @param list<string> $ids
     *
     * @return list<array{type: string, id: string}>
     */
    private function identifiers(array $ids): array
    {
        return \array_map(static fn(string $id): array => ['type' => 'players', 'id' => $id], $ids);
    }

    /**
     * @return array<string, mixed>
     */
    private function violation(string $detail, string $pointer): array
    {
        return [
            'status' => '422',
            'code' => 'VALIDATION_FAILED',
            'title' => 'Unprocessable Entity',
            'detail' => $detail,
            'source' => ['pointer' => $pointer],
        ];
    }

    /**
     * @param list<array<string, mixed>> $operations
     */
    private function atomic(array $operations): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $mediaType = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';
        $request = Request::create('/operations', 'POST', server: ['CONTENT_TYPE' => $mediaType, 'HTTP_ACCEPT' => $mediaType], content: \json_encode(['atomic:operations' => $operations], \JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $this->restoreHandlers();

        return $response;
    }

    /**
     * @return list<string>
     */
    private function playersOf(string $id): array
    {
        $response = $this->handle('/squads/' . $id . '/relationships/players');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            self::assertIsString($identifier['id'] ?? null);
            $ids[] = $identifier['id'];
        }
        \sort($ids);

        return $ids;
    }
}
