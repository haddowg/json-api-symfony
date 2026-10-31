<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use PHPUnit\Framework\Attributes\Test;

/**
 * A MorphToMany computed by extractUsing() and written by fillUsing() serves its
 * members on every read path whether or not a related type is scoped: the related
 * endpoint pages them, `?withCount` counts them, and a write goes through the hook.
 * The scoped variants hide agency 2, and every read shows the narrowed set.
 */
abstract class ComputedMorphToManyConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string COUNTS_ACCEPT = 'application/vnd.api+json;profile="' . CountableProfile::URI . '"';

    private const array USER_ONE = ['cm-agencies:1', 'cm-advertisers:1', 'cm-publishers:1', 'cm-agencies:2', 'cm-advertisers:2'];

    /**
     * Whether agency 2 is hidden from the agencies' provider.
     */
    abstract protected function scoped(): bool;

    /**
     * @return list<string> the user's stored companies as `type:id`, in stored order
     */
    abstract protected function storedCompanies(int $user): array;

    #[Test]
    public function theRelatedEndpointServesEveryTypeInOrder(): void
    {
        $document = $this->get('/cm-users/1/companies');

        self::assertSame($this->visible(self::USER_ONE), $this->keys($this->at($document, 'data')));
    }

    #[Test]
    public function theRelatedEndpointPagesTheMixedMembers(): void
    {
        $visible = $this->visible(self::USER_ONE);

        $first = $this->get('/cm-users/1/companies?page[number]=1&page[size]=2');
        self::assertSame(\array_slice($visible, 0, 2), $this->keys($this->at($first, 'data')));
        self::assertNotNull($this->at($first, 'links', 'next'));

        $second = $this->get('/cm-users/1/companies?page[number]=2&page[size]=2');
        self::assertSame(\array_slice($visible, 2, 2), $this->keys($this->at($second, 'data')));
        self::assertSame(2, $this->at($second, 'meta', 'page', 'currentPage'));

        $last = $this->get('/cm-users/1/companies?page[number]=3&page[size]=2');
        self::assertSame(\array_slice($visible, 4, 2), $this->keys($this->at($last, 'data')));
    }

    #[Test]
    public function theRelatedEndpointCountsItselfUnderWithCountSelf(): void
    {
        $count = \count($this->visible(self::USER_ONE));

        $document = $this->get('/cm-users/1/companies?withCount=_self_&page[size]=2', self::COUNTS_ACCEPT);

        self::assertCount(2, $this->keys($this->at($document, 'data')));
        self::assertSame($count, $this->at($document, 'meta', 'total'));
        self::assertSame($count, $this->at($document, 'meta', 'page', 'total'));
    }

    #[Test]
    public function theRelatedEndpointHonoursSparseFieldsPerType(): void
    {
        $document = $this->get('/cm-users/1/companies?fields[cm-agencies]=name');

        $data = $this->at($document, 'data');
        self::assertIsArray($data);
        self::assertNotSame([], $data);
        foreach ($data as $member) {
            $type = $this->at($member, 'type');
            $attributes = $this->at($member, 'attributes');
            self::assertIsArray($attributes);
            self::assertSame($type === 'cm-agencies' ? ['name'] : ['name', 'code'], \array_keys($attributes), (string) \json_encode($member));
        }
    }

    #[Test]
    public function withCountCountsTheRelationOnOneParent(): void
    {
        $document = $this->get('/cm-users/1?withCount=companies', self::COUNTS_ACCEPT);

        self::assertSame(\count($this->visible(self::USER_ONE)), $this->at($document, 'data', 'relationships', 'companies', 'meta', 'total'));
    }

    #[Test]
    public function withCountCountsTheRelationAcrossACollection(): void
    {
        $document = $this->get('/cm-users?withCount=companies', self::COUNTS_ACCEPT);

        $totals = [];
        $users = $this->at($document, 'data');
        self::assertIsArray($users);
        foreach ($users as $user) {
            $id = $this->at($user, 'id');
            self::assertIsString($id);
            $totals[$id] = $this->at($user, 'relationships', 'companies', 'meta', 'total');
        }

        self::assertSame(['1' => \count($this->visible(self::USER_ONE)), '2' => 1, '3' => 2], $totals);
    }

    #[Test]
    public function theLinkageRendersTheMembers(): void
    {
        $document = $this->get('/cm-users/1');

        self::assertSame($this->visible(self::USER_ONE), $this->keys($this->at($document, 'data', 'relationships', 'companies', 'data')));
    }

    #[Test]
    public function anIncludeRendersTheMembers(): void
    {
        $document = $this->get('/cm-users/1?include=companies');

        self::assertSame($this->visible(self::USER_ONE), $this->keys($this->at($document, 'data', 'relationships', 'companies', 'data')));
        $included = $this->keys($this->at($document, 'included'));
        \sort($included);
        $expected = $this->visible(self::USER_ONE);
        \sort($expected);
        self::assertSame($expected, $included);
    }

    #[Test]
    public function theRelationshipEndpointRendersTheMembers(): void
    {
        $document = $this->get('/cm-users/1/relationships/companies');

        self::assertSame($this->visible(self::USER_ONE), $this->keys($this->at($document, 'data')));
    }

    #[Test]
    public function aPostToTheRelationshipAddsThroughTheHook(): void
    {
        $response = $this->handle('/cm-users/3/relationships/companies', 'POST', ['data' => [
            ['type' => 'cm-advertisers', 'id' => '1'],
            ['type' => 'cm-publishers', 'id' => '1'],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $stored = ['cm-agencies:1', 'cm-publishers:1', 'cm-advertisers:1'];
        self::assertSame($stored, $this->storedCompanies(3));
        self::assertSame($stored, $this->keys($this->at($this->get('/cm-users/3/companies'), 'data')));
    }

    #[Test]
    public function aDeleteFromTheRelationshipRemovesThroughTheHook(): void
    {
        $response = $this->handle('/cm-users/3/relationships/companies', 'DELETE', ['data' => [
            ['type' => 'cm-agencies', 'id' => '1'],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(['cm-publishers:1'], $this->storedCompanies(3));
        self::assertSame(['cm-publishers:1'], $this->keys($this->at($this->get('/cm-users/3/companies'), 'data')));
    }

    #[Test]
    public function aPatchToTheRelationshipReplacesThroughTheHook(): void
    {
        $response = $this->handle('/cm-users/3/relationships/companies', 'PATCH', ['data' => [
            ['type' => 'cm-advertisers', 'id' => '2'],
            ['type' => 'cm-agencies', 'id' => '1'],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(['cm-agencies:1', 'cm-advertisers:2'], $this->storedCompanies(3));
        self::assertSame(['cm-agencies:1', 'cm-advertisers:2'], $this->keys($this->at($this->get('/cm-users/3/relationships/companies'), 'data')));
    }

    #[Test]
    public function aResourcePatchReplacesThroughTheHook(): void
    {
        $response = $this->handle('/cm-users/3', 'PATCH', ['data' => [
            'type' => 'cm-users',
            'id' => '3',
            'relationships' => ['companies' => ['data' => [['type' => 'cm-publishers', 'id' => '1'], ['type' => 'cm-advertisers', 'id' => '1']]]],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(['cm-publishers:1', 'cm-advertisers:1'], $this->storedCompanies(3));
        self::assertSame(['cm-publishers:1', 'cm-advertisers:1'], $this->keys($this->at($this->decode($response), 'data', 'relationships', 'companies', 'data')));
        self::assertSame(2, $this->at($this->get('/cm-users/3?withCount=companies', self::COUNTS_ACCEPT), 'data', 'relationships', 'companies', 'meta', 'total'));
    }

    #[Test]
    public function aCreateWritesThroughTheHook(): void
    {
        $response = $this->handle('/cm-users', 'POST', ['data' => [
            'type' => 'cm-users',
            'attributes' => ['name' => 'new'],
            'relationships' => ['companies' => ['data' => [['type' => 'cm-advertisers', 'id' => '2'], ['type' => 'cm-agencies', 'id' => '1']]]],
        ]]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $id = $this->at($this->decode($response), 'data', 'id');
        self::assertIsString($id);
        self::assertSame(['cm-advertisers:2', 'cm-agencies:1'], $this->storedCompanies((int) $id));
        self::assertSame(['cm-advertisers:2', 'cm-agencies:1'], $this->keys($this->at($this->get('/cm-users/' . $id . '/companies'), 'data')));
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function visible(array $keys): array
    {
        return $this->scoped() ? \array_values(\array_diff($keys, ['cm-agencies:2'])) : $keys;
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path, ?string $accept = null): array
    {
        $response = $this->handle($path, 'GET', null, $accept !== null ? ['HTTP_ACCEPT' => $accept] : []);
        self::assertSame(200, $response->getStatusCode(), $path . ': ' . $response->getContent());

        return $this->decode($response);
    }

    /**
     * @return list<string>
     */
    private function keys(mixed $data): array
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

    private function at(mixed $value, string ...$keys): mixed
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
