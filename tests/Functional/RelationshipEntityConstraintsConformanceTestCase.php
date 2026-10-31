<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A relationship write validates the changed resource against its entity-level
 * constraints before it commits. A violation is a 422 at the pointer the constraint
 * names and nothing is saved. User 1 has company 1. User 2 has companies 1 and 2
 * and a stored name that breaks the name's length constraint.
 */
abstract class RelationshipEntityConstraintsConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string AT_LEAST_ONE = '{"errors":[{"status":"422","code":"VALIDATION_FAILED","title":"Unprocessable Entity","detail":"An integration user needs at least one company.","source":{"pointer":"\/data\/relationships\/companies"}}],"jsonapi":{"version":"1.1"}}';

    #[Test]
    public function replacingAToManyWithNothingIsRefused(): void
    {
        $response = $this->handle('/integration-users/1/relationships/companies', 'PATCH', ['data' => []]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(self::AT_LEAST_ONE, $response->getContent());
        self::assertSame(['1'], $this->companiesOf('integration-users', '1'));
    }

    #[Test]
    public function replacingAToManyWithValidMembersIsSaved(): void
    {
        $response = $this->handle('/integration-users/1/relationships/companies', 'PATCH', ['data' => [['type' => 'companies', 'id' => '2']]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['2'], $this->companiesOf('integration-users', '1'));
    }

    #[Test]
    public function addingPastTheLimitIsRefused(): void
    {
        $response = $this->handle('/integration-users/1/relationships/companies', 'POST', ['data' => [['type' => 'companies', 'id' => '2'], ['type' => 'companies', 'id' => '3']]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            '{"errors":[{"status":"422","code":"VALIDATION_FAILED","title":"Unprocessable Entity","detail":"An integration user may have at most two companies.","source":{"pointer":"\/data\/relationships\/companies"}}],"jsonapi":{"version":"1.1"}}',
            $response->getContent(),
        );
        self::assertSame(['1'], $this->companiesOf('integration-users', '1'));
    }

    #[Test]
    public function addingWithinTheLimitIsSaved(): void
    {
        $response = $this->handle('/integration-users/1/relationships/companies', 'POST', ['data' => [['type' => 'companies', 'id' => '2']]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['1', '2'], $this->companiesOf('integration-users', '1'));
    }

    #[Test]
    public function removingTheLastMemberIsRefused(): void
    {
        $response = $this->handle('/integration-users/1/relationships/companies', 'DELETE', ['data' => [['type' => 'companies', 'id' => '1']]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(self::AT_LEAST_ONE, $response->getContent());
        self::assertSame(['1'], $this->companiesOf('integration-users', '1'));
    }

    #[Test]
    public function removingAMemberDoesNotRecheckTheAttributes(): void
    {
        $response = $this->handle('/integration-users/2/relationships/companies', 'DELETE', ['data' => [['type' => 'companies', 'id' => '2']]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['1'], $this->companiesOf('integration-users', '2'));
    }

    #[Test]
    public function clearingAToOneIsRefused(): void
    {
        $response = $this->handle('/integration-users/1/relationships/primaryCompany', 'PATCH', ['data' => null]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            '{"errors":[{"status":"422","code":"VALIDATION_FAILED","title":"Unprocessable Entity","detail":"An integration user needs a primary company.","source":{"pointer":"\/data\/relationships\/primaryCompany"}}],"jsonapi":{"version":"1.1"}}',
            $response->getContent(),
        );
        self::assertSame('1', $this->primaryCompanyOf('1'));
    }

    #[Test]
    public function replacingAToOneIsSaved(): void
    {
        $response = $this->handle('/integration-users/1/relationships/primaryCompany', 'PATCH', ['data' => ['type' => 'companies', 'id' => '2']]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('2', $this->primaryCompanyOf('1'));
    }

    #[Test]
    public function aTypeWithoutEntityConstraintsIsNotChecked(): void
    {
        $response = $this->handle('/teams/1/relationships/companies', 'PATCH', ['data' => []]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([], $this->companiesOf('teams', '1'));
    }

    #[Test]
    public function aViolationInAnAtomicBatchRollsBackTheWholeBatch(): void
    {
        $response = $this->atomic([
            ['op' => 'add', 'ref' => ['type' => 'integration-users', 'id' => '2', 'relationship' => 'companies'], 'data' => []],
            ['op' => 'update', 'ref' => ['type' => 'integration-users', 'id' => '1', 'relationship' => 'primaryCompany'], 'data' => ['type' => 'companies', 'id' => '2']],
            ['op' => 'remove', 'ref' => ['type' => 'integration-users', 'id' => '1', 'relationship' => 'companies'], 'data' => [['type' => 'companies', 'id' => '1']]],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            '{"errors":[{"status":"422","code":"VALIDATION_FAILED","title":"Unprocessable Entity","detail":"An integration user needs at least one company.","source":{"pointer":"\/atomic:operations\/2\/data\/relationships\/companies"}}],"jsonapi":{"version":"1.1","ext":["https:\/\/jsonapi.org\/ext\/atomic"]}}',
            $response->getContent(),
        );
        self::assertSame('1', $this->primaryCompanyOf('1'));
        self::assertSame(['1'], $this->companiesOf('integration-users', '1'));
    }

    #[Test]
    public function anAtomicBatchOfValidRelationshipWritesCommits(): void
    {
        $response = $this->atomic([
            ['op' => 'add', 'ref' => ['type' => 'integration-users', 'id' => '1', 'relationship' => 'companies'], 'data' => [['type' => 'companies', 'id' => '2']]],
            ['op' => 'remove', 'ref' => ['type' => 'integration-users', 'id' => '1', 'relationship' => 'companies'], 'data' => [['type' => 'companies', 'id' => '1']]],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['2'], $this->companiesOf('integration-users', '1'));
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
    private function companiesOf(string $type, string $id): array
    {
        $response = $this->handle('/' . $type . '/' . $id . '/relationships/companies');
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

    private function primaryCompanyOf(string $id): ?string
    {
        $response = $this->handle('/integration-users/' . $id . '/relationships/primaryCompany');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'] ?? null;
        if ($data === null) {
            return null;
        }
        self::assertIsArray($data);
        self::assertIsString($data['id'] ?? null);

        return $data['id'];
    }
}
