<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use PHPUnit\Framework\Attributes\Test;

/**
 * A MorphToMany declared with identifiedBy() over agencies, advertisers and publishers,
 * read from join rows and written by fillUsing(). The hiding variants hide agency 2
 * from the agencies' provider, so every read leaves it out before any page or count.
 *
 * The documents under `App/IdentifiedMorphMany/documents` are the exact response
 * bodies, shared byte for byte with the Laravel adapter's twin of this suite. Set
 * `UPDATE_MTM_DOCUMENTS=1` to rewrite them, and read the diff.
 */
abstract class IdentifiedMorphToManyConformanceTestCase extends JsonApiFunctionalTestCase
{
    public const string COUNTS_ACCEPT = 'application/vnd.api+json;profile="' . CountableProfile::URI . '"';

    /**
     * Each shared document: its request, the `Accept` it sends and its status.
     *
     * @var array<string, array{0: string, 1: ?string, 2: int}>
     */
    public const array DOCUMENTS = [
        'collection' => ['/users', null, 200],
        'include' => ['/users/1?include=companies', null, 200],
        'sparse-include' => ['/users/1?include=companies&fields[users]=companies&fields[agencies]=name', null, 200],
        'with-count' => ['/users?withCount=companies', self::COUNTS_ACCEPT, 200],
        'related' => ['/users/1/companies', null, 200],
        'related-page' => ['/users/1/companies?page[number]=2&page[size]=2&withCount=_self_', self::COUNTS_ACCEPT, 200],
        'related-sparse' => ['/users/1/companies?fields[advertisers]=code', null, 200],
        'relationship' => ['/users/1/relationships/companies', null, 200],
        'related-sort' => ['/users/1/companies?sort=name', null, 400],
        'related-filter' => ['/users/1/companies?filter[name]=Agency', null, 400],
    ];

    private const array USER_ONE = ['agencies:1', 'advertisers:1', 'publishers:1', 'agencies:2', 'advertisers:2', 'agencies:3'];

    /**
     * Whether agency 2 is hidden from the agencies' provider.
     */
    abstract protected function hides(): bool;

    /**
     * @return list<string> the user's stored companies as `type:id`, in stored order
     */
    abstract protected function storedCompanies(int $user): array;

    /**
     * Stores a join row naming `$type` for user `$user`.
     */
    abstract protected function storeCompany(int $user, string $type, int $id): void;

    #[Test]
    public function everyResponseIsItsSharedDocument(): void
    {
        foreach (self::DOCUMENTS as $name => [$path, $accept, $status]) {
            $response = $this->handle($path, 'GET', null, $accept !== null ? ['HTTP_ACCEPT' => $accept] : []);
            self::assertSame($status, $response->getStatusCode(), $path . ': ' . $response->getContent());

            $file = __DIR__ . '/App/IdentifiedMorphMany/documents/' . ($this->hides() ? 'hidden' : 'plain') . '/' . $name . '.json';
            if (\getenv('UPDATE_MTM_DOCUMENTS') === '1') {
                if (!\is_dir(\dirname($file))) {
                    \mkdir(\dirname($file), 0o777, true);
                }
                \file_put_contents($file, (string) $response->getContent());
            }

            self::assertFileExists($file);
            self::assertSame(\file_get_contents($file), $response->getContent(), $path);
        }
    }

    #[Test]
    public function theLinkageKeepsPairOrderAndDropsOnlyAHiddenMember(): void
    {
        self::assertSame($this->visible(self::USER_ONE), $this->keys($this->at($this->get('/users/1'), 'data', 'relationships', 'companies', 'data')));
    }

    #[Test]
    public function theRelatedEndpointPagesInPairOrderWithExactTotals(): void
    {
        $visible = $this->visible(self::USER_ONE);
        $total = \count($visible);

        foreach ([1, 2, 3] as $number) {
            $document = $this->get('/users/1/companies?withCount=_self_&page[size]=2&page[number]=' . $number, self::COUNTS_ACCEPT);
            self::assertSame(\array_slice($visible, ($number - 1) * 2, 2), $this->keys($this->at($document, 'data')), 'page ' . $number);
            self::assertSame($total, $this->at($document, 'meta', 'total'));
            self::assertSame($total, $this->at($document, 'meta', 'page', 'total'));
        }
    }

    #[Test]
    public function withCountCountsTheAdmittedPairsOnOneParent(): void
    {
        $document = $this->get('/users/1?withCount=companies', self::COUNTS_ACCEPT);

        self::assertSame(\count($this->visible(self::USER_ONE)), $this->at($document, 'data', 'relationships', 'companies', 'meta', 'total'));
    }

    #[Test]
    public function anIncludeRendersEachAdmittedMemberOnce(): void
    {
        $document = $this->get('/users?include=companies');

        $included = $this->keys($this->at($document, 'included'));
        $expected = $this->visible(['agencies:1', 'advertisers:1', 'publishers:1', 'agencies:2', 'advertisers:2', 'agencies:3']);
        self::assertSame($expected, $included);
    }

    #[Test]
    public function theRelationshipEndpointRefusesAPage(): void
    {
        $response = $this->handle('/users/1/relationships/companies?page[size]=2');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    public function anUndeclaredStoredTypeIsAServerError(): void
    {
        $this->storeCompany(2, 'regions', 1);

        $response = $this->handle('/users/2');

        self::assertSame(500, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    public function aReplaceWritesThroughTheHookAndKeepsAHiddenMember(): void
    {
        $response = $this->handle('/users/1/relationships/companies', 'PATCH', ['data' => [
            ['type' => 'publishers', 'id' => '1'],
            ['type' => 'agencies', 'id' => '1'],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $stored = $this->hides() ? ['agencies:1', 'publishers:1', 'agencies:2'] : ['agencies:1', 'publishers:1'];
        self::assertSame($stored, $this->storedCompanies(1));
        self::assertSame(['agencies:1', 'publishers:1'], $this->keys($this->at($this->get('/users/1/relationships/companies'), 'data')));
    }

    #[Test]
    public function anAddWritesThroughTheHook(): void
    {
        $response = $this->handle('/users/3/relationships/companies', 'POST', ['data' => [
            ['type' => 'advertisers', 'id' => '1'],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(['agencies:1', 'publishers:1', 'advertisers:1'], $this->storedCompanies(3));
        self::assertSame(['agencies:1', 'publishers:1', 'advertisers:1'], $this->keys($this->at($this->get('/users/3/companies'), 'data')));
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    protected function visible(array $keys): array
    {
        return $this->hides() ? \array_values(\array_diff($keys, ['agencies:2'])) : $keys;
    }

    /**
     * @return array<string, mixed>
     */
    protected function get(string $path, ?string $accept = null): array
    {
        $response = $this->handle($path, 'GET', null, $accept !== null ? ['HTTP_ACCEPT' => $accept] : []);
        self::assertSame(200, $response->getStatusCode(), $path . ': ' . $response->getContent());

        return $this->decode($response);
    }

    /**
     * @return list<string>
     */
    protected function keys(mixed $data): array
    {
        self::assertIsArray($data);

        $keys = [];
        foreach ($data as $member) {
            $type = $this->at($member, 'type');
            $id = $this->at($member, 'id');
            self::assertIsString($type);
            self::assertIsString($id);
            $keys[] = $type . ':' . $id;
        }

        return $keys;
    }

    protected function at(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value)) {
                return null;
            }
            $value = $value[$key] ?? null;
        }

        return $value;
    }
}
