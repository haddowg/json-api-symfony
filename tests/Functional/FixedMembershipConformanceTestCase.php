<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A to-many declared `cannotChangeMembership()` on both providers. Article 1 holds
 * comments 1 and 2; `fixedComments` and `gatedComments` read the same column as the
 * unflagged `comments`, and `gatedComments` is fixed only for a caller who is not an
 * admin.
 *
 * A replacement may restate the members in any order, on the relationship endpoint and
 * in a whole-resource `PATCH`. Any other set is a `403` sourced at the relationship, and
 * add and remove are closed with their own `403`s.
 */
abstract class FixedMembershipConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const array ADMIN = ['HTTP_X_ROLE' => 'admin'];

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aReplacementThatReordersTheMembersIsAllowed(): void
    {
        $response = $this->handle('/articles/1/relationships/fixedComments', 'PATCH', ['data' => [
            ['type' => 'comments', 'id' => '2'],
            ['type' => 'comments', 'id' => '1'],
            ['type' => 'comments', 'id' => '2'],
        ]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['1', '2'], $this->memberIds('fixedComments'));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aReplacementThatDropsAMemberIsForbidden(): void
    {
        $response = $this->handle('/articles/1/relationships/fixedComments', 'PATCH', ['data' => [
            ['type' => 'comments', 'id' => '1'],
        ]]);

        $this->assertMembershipChangeProhibited($response, 'fixedComments');
        self::assertSame(['1', '2'], $this->memberIds('fixedComments'));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aReplacementThatAddsAMemberIsForbidden(): void
    {
        $response = $this->handle('/articles/1/relationships/fixedComments', 'PATCH', ['data' => [
            ['type' => 'comments', 'id' => '1'],
            ['type' => 'comments', 'id' => '2'],
            ['type' => 'comments', 'id' => '4'],
        ]]);

        $this->assertMembershipChangeProhibited($response, 'fixedComments');
        self::assertSame(['1', '2'], $this->memberIds('fixedComments'));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function aWholeResourceUpdateThatReordersTheMembersIsAllowed(): void
    {
        $response = $this->handle('/articles/1', 'PATCH', ['data' => [
            'type' => 'articles',
            'id' => '1',
            'attributes' => ['title' => 'Reordered'],
            'relationships' => ['fixedComments' => ['data' => [
                ['type' => 'comments', 'id' => '2'],
                ['type' => 'comments', 'id' => '1'],
            ]]],
        ]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['1', '2'], $this->memberIds('fixedComments'));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function aWholeResourceUpdateThatChangesTheMembersIsForbidden(): void
    {
        $response = $this->handle('/articles/1', 'PATCH', ['data' => [
            'type' => 'articles',
            'id' => '1',
            'attributes' => ['title' => 'Changed'],
            'relationships' => ['fixedComments' => ['data' => [
                ['type' => 'comments', 'id' => '4'],
            ]]],
        ]]);

        $this->assertMembershipChangeProhibited($response, 'fixedComments');
        self::assertSame(['1', '2'], $this->memberIds('fixedComments'));
        self::assertSame('JSON:API in PHP', $this->title('1'));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function addingAndRemovingAreClosed(): void
    {
        $add = $this->handle('/articles/1/relationships/fixedComments', 'POST', ['data' => [['type' => 'comments', 'id' => '4']]]);
        self::assertSame(403, $add->getStatusCode(), (string) $add->getContent());
        self::assertSame('ADDITION_PROHIBITED', $this->error($add)['code'] ?? null);

        $remove = $this->handle('/articles/1/relationships/fixedComments', 'DELETE', ['data' => [['type' => 'comments', 'id' => '1']]]);
        self::assertSame(403, $remove->getStatusCode(), (string) $remove->getContent());
        self::assertSame('REMOVAL_PROHIBITED', $this->error($remove)['code'] ?? null);

        self::assertSame(['1', '2'], $this->memberIds('fixedComments'));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aClosureFixesTheMembersOnlyForTheRequestsItMatches(): void
    {
        $refused = $this->handle('/articles/1/relationships/gatedComments', 'PATCH', ['data' => [['type' => 'comments', 'id' => '1']]]);
        $this->assertMembershipChangeProhibited($refused, 'gatedComments');

        $add = $this->handle('/articles/1/relationships/gatedComments', 'POST', ['data' => [['type' => 'comments', 'id' => '4']]]);
        self::assertSame('ADDITION_PROHIBITED', $this->error($add)['code'] ?? null);

        $allowed = $this->handle('/articles/1/relationships/gatedComments', 'PATCH', ['data' => [['type' => 'comments', 'id' => '1']]], self::ADMIN);
        self::assertSame(200, $allowed->getStatusCode(), (string) $allowed->getContent());
        self::assertSame(['1'], $this->memberIds('gatedComments'));

        $added = $this->handle('/articles/1/relationships/gatedComments', 'POST', ['data' => [['type' => 'comments', 'id' => '4']]], self::ADMIN);
        self::assertSame(200, $added->getStatusCode(), (string) $added->getContent());
        self::assertSame(['1', '4'], $this->memberIds('gatedComments'));
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function aCreateSetsTheMembersFreely(): void
    {
        $response = $this->handle('/articles', 'POST', ['data' => [
            'type' => 'articles',
            'attributes' => ['title' => 'Fixed from the start', 'body' => 'Body.', 'category' => 'news'],
            'relationships' => ['fixedComments' => ['data' => [
                ['type' => 'comments', 'id' => '1'],
                ['type' => 'comments', 'id' => '2'],
            ]]],
        ]]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $id = self::dig($this->decode($response), 'data', 'id');
        self::assertIsString($id);
        self::assertSame(['1', '2'], $this->memberIds('fixedComments', $id));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aToManyWithoutTheFlagIsUnaffected(): void
    {
        $response = $this->handle('/articles/1/relationships/comments', 'PATCH', ['data' => [['type' => 'comments', 'id' => '4']]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['4'], $this->memberIds('comments'));
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anAtomicReplacementThatChangesTheMembersRollsBackTheBatch(): void
    {
        $response = $this->atomic([
            [
                'op' => 'update',
                'ref' => ['type' => 'articles', 'id' => '1'],
                'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['title' => 'Rolled back']],
            ],
            [
                'op' => 'update',
                'ref' => ['type' => 'articles', 'id' => '1', 'relationship' => 'fixedComments'],
                'data' => [['type' => 'comments', 'id' => '1']],
            ],
        ]);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        $error = $this->error($response);
        self::assertSame('MEMBERSHIP_CHANGE_PROHIBITED', $error['code'] ?? null);
        self::assertSame('/atomic:operations/1/data/relationships/fixedComments', self::dig($error, 'source', 'pointer'));
        self::assertSame('JSON:API in PHP', $this->title('1'));
        self::assertSame(['1', '2'], $this->memberIds('fixedComments'));
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anAtomicMemberNamedOnlyByLidIsNew(): void
    {
        $response = $this->atomic([
            [
                'op' => 'add',
                'data' => ['type' => 'comments', 'lid' => 'fresh', 'attributes' => ['body' => 'New comment']],
            ],
            [
                'op' => 'update',
                'ref' => ['type' => 'articles', 'id' => '1'],
                'data' => ['type' => 'articles', 'id' => '1', 'relationships' => ['fixedComments' => ['data' => [
                    ['type' => 'comments', 'id' => '1'],
                    ['type' => 'comments', 'id' => '2'],
                    ['type' => 'comments', 'lid' => 'fresh'],
                ]]]],
            ],
        ]);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        $error = $this->error($response);
        self::assertSame('MEMBERSHIP_CHANGE_PROHIBITED', $error['code'] ?? null);
        self::assertSame('/atomic:operations/1/data/relationships/fixedComments', self::dig($error, 'source', 'pointer'));
        self::assertSame(['1', '2'], $this->memberIds('fixedComments'));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theDocumentDropsAddAndRemoveForAFixedRelationAndCataloguesTheCode(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $document = $factory->forServer()->toArray();
        $paths = $document['paths'] ?? null;
        self::assertIsArray($paths);

        $verbs = static fn(string $relation): array => \array_values(\array_intersect(
            ['get', 'patch', 'post', 'delete'],
            \array_keys(\is_array($paths['/articles/{id}/relationships/' . $relation] ?? null) ? $paths['/articles/{id}/relationships/' . $relation] : []),
        ));
        self::assertSame(['get', 'patch'], $verbs('fixedComments'));
        // A closure decides per request, so every verb stays.
        self::assertSame(['get', 'patch', 'post', 'delete'], $verbs('gatedComments'));

        $schemas = self::dig($document, 'components', 'schemas');
        self::assertIsArray($schemas);
        self::assertSame('Error403PointerMembershipChangeProhibited', self::errorShapeOf($schemas, 'MEMBERSHIP_CHANGE_PROHIBITED'));
        self::assertSame(\max(GeneratorContract::BASELINE, GeneratorContract::FIXED_MEMBERSHIP), self::dig($document, 'info', 'x-generator', 'contract'));
    }

    private function assertMembershipChangeProhibited(Response $response, string $relation): void
    {
        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        $error = $this->error($response);
        self::assertSame('MEMBERSHIP_CHANGE_PROHIBITED', $error['code'] ?? null);
        self::assertSame('/data/relationships/' . $relation, self::dig($error, 'source', 'pointer'));
    }

    /**
     * @return array<string, mixed>
     */
    private function error(Response $response): array
    {
        $error = self::dig($this->decode($response), 'errors', 0);
        self::assertIsArray($error, (string) $response->getContent());

        /** @var array<string, mixed> $error */
        return $error;
    }

    /**
     * @return list<string> the article's members of `$relation`, sorted
     */
    private function memberIds(string $relation, string $article = '1'): array
    {
        $this->detachPersistedState();
        $response = $this->handle('/articles/' . $article . '/relationships/' . $relation);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $ids = [];
        foreach ((array) ($this->decode($response)['data'] ?? []) as $identifier) {
            self::assertIsArray($identifier);
            self::assertIsString($identifier['id'] ?? null);
            $ids[] = $identifier['id'];
        }
        \sort($ids);

        return $ids;
    }

    private function title(string $article): mixed
    {
        $this->detachPersistedState();

        return self::dig($this->decode($this->handle('/articles/' . $article)), 'data', 'attributes', 'title');
    }

    /**
     * @param list<array<string, mixed>> $operations
     */
    private function atomic(array $operations): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $ext = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';
        $request = Request::create('/operations', 'POST', server: ['CONTENT_TYPE' => $ext, 'HTTP_ACCEPT' => $ext], content: \json_encode(['atomic:operations' => $operations], \JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $this->restoreHandlers();

        return $response;
    }

    protected function detachPersistedState(): void {}

    /**
     * The value at `$keys` inside `$value`, or `null` when a step is missing.
     */
    private static function dig(mixed $value, string|int ...$keys): mixed
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
