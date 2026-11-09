<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;

/**
 * A `belongsToMany` whose pivot fields are all computed is served over its plain join
 * table, with no association entity: each linkage member and related resource carries a
 * `meta.pivot` its hooks work out from the crew and the member, and a write ignores any
 * computed field it sends. Crew 1 ("Reds") holds Ada and Linus; `badge` is hidden from
 * Linus.
 */
abstract class ComputedPivotConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const array ADA = ['role' => 'Reds:Ada', 'badge' => 'ADA'];
    private const array LINUS = ['role' => 'Reds:Linus'];

    #[Test]
    public function theRelatedEndpointRendersTheComputedPivot(): void
    {
        self::assertSame(['1' => self::ADA, '3' => self::LINUS], $this->pivots($this->get('/cp-crews/1/members')));
    }

    #[Test]
    public function theRelationshipEndpointRendersTheComputedPivot(): void
    {
        self::assertSame(['1' => self::ADA, '3' => self::LINUS], $this->pivots($this->get('/cp-crews/1/relationships/members')));
    }

    #[Test]
    public function anIncludedLinkageRendersTheComputedPivot(): void
    {
        $document = $this->get('/cp-crews/1?include=members');

        self::assertSame(['1' => self::ADA, '3' => self::LINUS], $this->pivots($this->linkage($document, 'members')));
    }

    #[Test]
    public function aComputedFieldIsNoSortKey(): void
    {
        $response = $this->handle('/cp-crews/1/members?sort=role');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    public function replacingIgnoresTheComputedFieldsItSends(): void
    {
        $response = $this->handle('/cp-crews/1/relationships/members', 'PATCH', ['data' => [
            ['type' => 'cp-members', 'id' => '2', 'meta' => ['pivot' => ['role' => 'captain']]],
        ]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['2' => ['role' => 'Reds:Grace', 'badge' => 'GRACE']], $this->pivots($this->get('/cp-crews/1/relationships/members')));
    }

    #[Test]
    public function addingAndRemovingWorkOverThePlainJoinTable(): void
    {
        $added = $this->handle('/cp-crews/1/relationships/members', 'POST', ['data' => [
            ['type' => 'cp-members', 'id' => '2', 'meta' => ['pivot' => ['badge' => 'X']]],
        ]]);
        self::assertSame(200, $added->getStatusCode(), (string) $added->getContent());
        self::assertSame(['1', '2', '3'], $this->sortedIds($this->get('/cp-crews/1/relationships/members')));

        $removed = $this->handle('/cp-crews/1/relationships/members', 'DELETE', ['data' => [['type' => 'cp-members', 'id' => '1']]]);
        self::assertSame(200, $removed->getStatusCode(), (string) $removed->getContent());
        self::assertSame(['2', '3'], $this->sortedIds($this->get('/cp-crews/1/relationships/members')));
    }

    #[Test]
    public function aCreateSendingComputedFieldsIsSaved(): void
    {
        $response = $this->handle('/cp-crews', 'POST', ['data' => [
            'type' => 'cp-crews',
            'id' => '9',
            'attributes' => ['name' => 'Blues'],
            'relationships' => ['members' => ['data' => [['type' => 'cp-members', 'id' => '2', 'meta' => ['pivot' => ['role' => 'captain']]]]]],
        ]]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['2' => ['role' => 'Blues:Grace', 'badge' => 'GRACE']], $this->pivots($this->get('/cp-crews/9/members')));
    }

    /**
     * @return array<string, mixed>
     */
    protected function get(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), $path . ': ' . $response->getContent());

        return $this->decode($response);
    }

    /**
     * The primary resource's linkage for `$relation`, as a document of its own.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    protected function linkage(array $document, string $relation): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);
        $relationship = $relationships[$relation] ?? null;
        self::assertIsArray($relationship);

        return ['data' => $relationship['data'] ?? null];
    }

    /**
     * Each member's `meta.pivot`, keyed by id in id order.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    protected function pivots(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $pivots = [];
        foreach ($data as $member) {
            self::assertIsArray($member);
            self::assertIsString($member['id'] ?? null);
            $meta = $member['meta'] ?? null;
            self::assertIsArray($meta, (string) \json_encode($member));
            $pivots[$member['id']] = $meta['pivot'] ?? null;
        }
        \ksort($pivots);

        return $pivots;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function sortedIds(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $ids = [];
        foreach ($data as $member) {
            self::assertIsArray($member);
            self::assertIsString($member['id'] ?? null);
            $ids[] = $member['id'];
        }
        \sort($ids);

        return $ids;
    }
}
