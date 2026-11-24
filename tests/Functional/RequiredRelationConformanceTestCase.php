<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A relation declared required is enforced as a required attribute is: a create must
 * send it, and a write that sends it may not send it empty. Every task has an owner,
 * and a new task names at least one watcher. Task 1 is owned and watched by member 1.
 */
abstract class RequiredRelationConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string MISSING = 'This field is missing.';
    private const string NULL = 'This value should not be null.';
    private const string BLANK = 'This value should not be blank.';

    #[Test]
    public function aCreateWithoutRelationshipsIsRefused(): void
    {
        $response = $this->handle('/tasks', 'POST', ['data' => ['type' => 'tasks', 'id' => '9', 'attributes' => ['title' => 'Ship']]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([
            $this->violation(self::MISSING, '/data/relationships/owner'),
            $this->violation(self::MISSING, '/data/relationships/watchers'),
        ], $this->decode($response)['errors'] ?? null);
        self::assertSame(404, $this->handle('/tasks/9')->getStatusCode());
    }

    #[Test]
    public function aCreateWithEmptyRelationshipsIsRefused(): void
    {
        $response = $this->handle('/tasks', 'POST', ['data' => ['type' => 'tasks', 'id' => '9', 'attributes' => ['title' => 'Ship'], 'relationships' => new \stdClass()]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([
            $this->violation(self::MISSING, '/data/relationships/owner'),
            $this->violation(self::MISSING, '/data/relationships/watchers'),
        ], $this->decode($response)['errors'] ?? null);
        self::assertSame(404, $this->handle('/tasks/9')->getStatusCode());
    }

    #[Test]
    public function aCreateWithANullOwnerIsRefused(): void
    {
        $response = $this->handle('/tasks', 'POST', $this->task('9', null, ['1']));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::NULL, '/data/relationships/owner/data')], $this->decode($response)['errors'] ?? null);
        self::assertSame(404, $this->handle('/tasks/9')->getStatusCode());
    }

    #[Test]
    public function aCreateWithNoWatchersIsRefused(): void
    {
        $response = $this->handle('/tasks', 'POST', $this->task('9', '1', []));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::BLANK, '/data/relationships/watchers/data')], $this->decode($response)['errors'] ?? null);
        self::assertSame(404, $this->handle('/tasks/9')->getStatusCode());
    }

    #[Test]
    public function aCreateWithEveryRequiredRelationshipIsSaved(): void
    {
        $response = $this->handle('/tasks', 'POST', $this->task('9', '2', ['1', '2']));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('2', $this->ownerOf('9'));
    }

    #[Test]
    public function anUpdateSettingTheOwnerToNullIsRefused(): void
    {
        $response = $this->handle('/tasks/1', 'PATCH', ['data' => ['type' => 'tasks', 'id' => '1', 'relationships' => ['owner' => ['data' => null]]]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::NULL, '/data/relationships/owner/data')], $this->decode($response)['errors'] ?? null);
        self::assertSame('1', $this->ownerOf('1'));
    }

    #[Test]
    public function anUpdateLeavingTheRelationshipsOutIsSaved(): void
    {
        $response = $this->handle('/tasks/1', 'PATCH', ['data' => ['type' => 'tasks', 'id' => '1', 'attributes' => ['title' => 'Replan']]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('1', $this->ownerOf('1'));
    }

    #[Test]
    public function anUpdateMayEmptyARelationRequiredOnlyOnCreate(): void
    {
        $response = $this->handle('/tasks/1', 'PATCH', ['data' => ['type' => 'tasks', 'id' => '1', 'relationships' => ['watchers' => ['data' => []]]]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    public function clearingTheOwnerThroughItsRelationshipEndpointIsRefused(): void
    {
        $response = $this->handle('/tasks/1/relationships/owner', 'PATCH', ['data' => null]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::NULL, '/data')], $this->decode($response)['errors'] ?? null);
        self::assertSame('1', $this->ownerOf('1'));
    }

    #[Test]
    public function anAtomicAddWithoutTheOwnerIsRefused(): void
    {
        $response = $this->atomic([
            ['op' => 'add', 'data' => $this->task('8', '1', ['1'])['data']],
            ['op' => 'add', 'data' => ['type' => 'tasks', 'id' => '9', 'attributes' => ['title' => 'Ship'], 'relationships' => ['watchers' => ['data' => [['type' => 'members', 'id' => '1']]]]]],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::MISSING, '/atomic:operations/1/data/relationships/owner')], $this->decode($response)['errors'] ?? null);
        self::assertSame(404, $this->handle('/tasks/8')->getStatusCode());
        self::assertSame(404, $this->handle('/tasks/9')->getStatusCode());
    }

    #[Test]
    public function anAtomicAddWithEveryRequiredRelationshipCommits(): void
    {
        $response = $this->atomic([
            ['op' => 'add', 'data' => $this->task('9', '2', ['2'])['data']],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('2', $this->ownerOf('9'));
    }

    #[Test]
    public function anActionTakingTheTypesCreateRulesRefusesAMissingOwner(): void
    {
        $response = $this->handle('/tasks/-actions/draft', 'POST', ['data' => ['type' => 'tasks', 'attributes' => ['title' => 'Ship'], 'relationships' => ['watchers' => ['data' => [['type' => 'members', 'id' => '1']]]]]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$this->violation(self::MISSING, '/data/relationships/owner')], $this->decode($response)['errors'] ?? null);
    }

    /**
     * @param list<string> $watchers
     *
     * @return array{data: array<string, mixed>}
     */
    private function task(string $id, ?string $owner, array $watchers): array
    {
        return ['data' => [
            'type' => 'tasks',
            'id' => $id,
            'attributes' => ['title' => 'Ship'],
            'relationships' => [
                'owner' => ['data' => $owner === null ? null : ['type' => 'members', 'id' => $owner]],
                'watchers' => ['data' => \array_map(static fn(string $member): array => ['type' => 'members', 'id' => $member], $watchers)],
            ],
        ]];
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

    private function ownerOf(string $id): ?string
    {
        $response = $this->handle('/tasks/' . $id . '/relationships/owner');
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
