<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A crew's pivot relation renders the same `meta.pivot` when it is reached through a
 * nested include as when the crew is the primary resource: on each member of the crew's
 * linkage and on each included member. Ships belong to a crew and fleets hold ships, so
 * `cp-ships?include=crew.members` reaches `members` one level deep and
 * `cp-fleets?include=ships.crew.members` two levels deep. Crew 1 ("Reds") holds Ada and
 * Linus, crew 2 ("Blues") Ada and Grace, and `badge` is hidden from Linus.
 */
abstract class NestedPivotIncludeConformanceTestCase extends JsonApiFunctionalTestCase
{
    protected const array REDS = ['1' => ['role' => 'Reds:Ada', 'badge' => 'ADA'], '3' => ['role' => 'Reds:Linus']];
    protected const array BLUES = ['1' => ['role' => 'Blues:Ada', 'badge' => 'ADA'], '2' => ['role' => 'Blues:Grace', 'badge' => 'GRACE']];

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aNestedIncludeOnACollectionRendersEachCrewsPivot(): void
    {
        $document = $this->get('/cp-ships?include=crew.members');

        self::assertSame(self::REDS, $this->pivots($this->includedLinkage($document, 'cp-crews', '1', 'members')));
        self::assertSame(self::BLUES, $this->pivots($this->includedLinkage($document, 'cp-crews', '2', 'members')));

        // Ada sits in both crews, so her included resource carries one of her two links.
        $members = $this->includedPivots($document, 'cp-members');
        self::assertSame([1, 2, 3], \array_keys($members));
        self::assertContains($members['1'] ?? null, [self::REDS['1'], self::BLUES['1']]);
        self::assertSame(self::BLUES['2'], $members['2'] ?? null);
        self::assertSame(self::REDS['3'], $members['3'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aNestedIncludeOnASingleReadRendersTheCrewsPivot(): void
    {
        $document = $this->get('/cp-ships/2?include=crew.members');

        self::assertSame(self::BLUES, $this->pivots($this->includedLinkage($document, 'cp-crews', '2', 'members')));
        self::assertSame(self::BLUES, $this->includedPivots($document, 'cp-members'));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aPivotRelationTwoIncludesDeepRendersItsPivot(): void
    {
        $document = $this->get('/cp-fleets/1?include=ships.crew.members');

        self::assertSame(self::REDS, $this->pivots($this->includedLinkage($document, 'cp-crews', '1', 'members')));
        self::assertSame(self::BLUES, $this->pivots($this->includedLinkage($document, 'cp-crews', '2', 'members')));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aPivotRelationTwoIncludesDeepRendersItsPivotOnACollection(): void
    {
        $document = $this->get('/cp-fleets?include=ships.crew.members');

        self::assertSame(self::REDS, $this->pivots($this->includedLinkage($document, 'cp-crews', '1', 'members')));
        self::assertSame(self::BLUES, $this->pivots($this->includedLinkage($document, 'cp-crews', '2', 'members')));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aCrewReachedByTwoPathsRendersItsPivotOnce(): void
    {
        // Crew 1 sails ship 1 (through `ships`) and ship 3 (the flagship).
        $document = $this->get('/cp-fleets/1?include=ships.crew.members,flagship.crew.members');

        self::assertCount(1, \array_filter(
            $this->included($document),
            static fn(array $resource): bool => $resource['type'] === 'cp-crews' && $resource['id'] === '1',
        ));
        self::assertSame(self::REDS, $this->pivots($this->includedLinkage($document, 'cp-crews', '1', 'members')));
        self::assertSame(self::BLUES, $this->pivots($this->includedLinkage($document, 'cp-crews', '2', 'members')));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aMemberReachedByAPivotPathAndAPlainPathKeepsTheLinkagePivot(): void
    {
        // Ada is ship 1's captain and a member of its crew.
        $document = $this->get('/cp-ships/1?include=captain,crew.members');

        self::assertSame(self::REDS, $this->pivots($this->includedLinkage($document, 'cp-crews', '1', 'members')));
        self::assertCount(1, \array_filter(
            $this->included($document),
            static fn(array $resource): bool => $resource['type'] === 'cp-members' && $resource['id'] === '1',
        ));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aSparseFieldsetOnTheNestedPathKeepsThePivot(): void
    {
        $document = $this->get('/cp-ships/2?include=crew.members&fields[cp-crews]=members&fields[cp-members]=name');

        self::assertSame(self::BLUES, $this->pivots($this->includedLinkage($document, 'cp-crews', '2', 'members')));
        self::assertSame(self::BLUES, $this->includedPivots($document, 'cp-members'));
        foreach ($this->included($document) as $resource) {
            if ($resource['type'] === 'cp-members') {
                self::assertSame(['name'], \array_keys((array) ($resource['attributes'] ?? [])));
            }
        }
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aNotSparseRelationOnTheIncludePathStaysOutsideTheFieldset(): void
    {
        // A ship's `crew` is declared notSparseField().
        $document = $this->get('/cp-ships/2?include=crew.members&fields[cp-ships]=name');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);
        self::assertSame(['crew'], \array_keys($relationships));
        self::assertSame(self::BLUES, $this->pivots($this->includedLinkage($document, 'cp-crews', '2', 'members')));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aFieldsetLeavingOutTheNestedRelationStillRendersTheIncludedPivot(): void
    {
        $document = $this->get('/cp-ships/2?include=crew.members&fields[cp-crews]=name');

        $crew = $this->includedResource($document, 'cp-crews', '2');
        self::assertArrayNotHasKey('relationships', $crew);
        self::assertSame(self::BLUES, $this->includedPivots($document, 'cp-members'));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function theRelatedEndpointRendersThePivotOfItsIncludes(): void
    {
        $document = $this->get('/cp-ships/2/crew?include=members');
        self::assertSame(self::BLUES, $this->pivots($this->linkage($document, 'members')));
        self::assertSame(self::BLUES, $this->includedPivots($document, 'cp-members'));

        $document = $this->get('/cp-fleets/1/ships?include=crew.members');
        self::assertSame(self::REDS, $this->pivots($this->includedLinkage($document, 'cp-crews', '1', 'members')));
        self::assertSame(self::BLUES, $this->pivots($this->includedLinkage($document, 'cp-crews', '2', 'members')));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aWriteResponseRendersThePivotOfItsIncludes(): void
    {
        $response = $this->handle('/cp-ships/2?include=crew.members', 'PATCH', ['data' => [
            'type' => 'cp-ships',
            'id' => '2',
            'attributes' => ['name' => 'Barque'],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);

        self::assertSame(self::BLUES, $this->pivots($this->includedLinkage($document, 'cp-crews', '2', 'members')));
        self::assertSame(self::BLUES, $this->includedPivots($document, 'cp-members'));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aCreateResponseRendersThePivotOfItsInclude(): void
    {
        $response = $this->handle('/cp-crews?include=members', 'POST', ['data' => [
            'type' => 'cp-crews',
            'id' => '7',
            'attributes' => ['name' => 'Greens'],
            'relationships' => ['members' => ['data' => [['type' => 'cp-members', 'id' => '1']]]],
        ]]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);

        $greens = ['1' => ['role' => 'Greens:Ada', 'badge' => 'ADA']];
        self::assertSame($greens, $this->pivots($this->linkage($document, 'members')));
        self::assertSame($greens, $this->includedPivots($document, 'cp-members'));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aPrimaryIncludeIsUnchanged(): void
    {
        $document = $this->get('/cp-crews/2?include=members');

        self::assertSame(self::BLUES, $this->pivots($this->linkage($document, 'members')));
        self::assertSame(self::BLUES, $this->includedPivots($document, 'cp-members'));
        self::assertSame(self::BLUES, $this->pivots($this->get('/cp-crews/2/members')));
        self::assertSame(self::BLUES, $this->pivots($this->get('/cp-crews/2/relationships/members')));
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
     * @param array<string, mixed> $document
     *
     * @return list<array<string, mixed>>
     */
    protected function included(array $document): array
    {
        $included = $document['included'] ?? [];
        self::assertIsArray($included);

        $resources = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            /** @var array<string, mixed> $resource */
            $resources[] = $resource;
        }

        return $resources;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    protected function includedResource(array $document, string $type, string $id): array
    {
        foreach ($this->included($document) as $resource) {
            if ($resource['type'] === $type && $resource['id'] === $id) {
                return $resource;
            }
        }

        self::fail(\sprintf('%s %s is not included: %s', $type, $id, \json_encode($document)));
    }

    /**
     * The linkage of `$relation` on an included resource, as a document of its own.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    protected function includedLinkage(array $document, string $type, string $id, string $relation): array
    {
        return $this->linkage(['data' => $this->includedResource($document, $type, $id)], $relation);
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
        self::assertIsArray($relationships, (string) \json_encode($data));
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
     * Each included resource of `$type`'s `meta.pivot`, keyed by id in id order.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    protected function includedPivots(array $document, string $type): array
    {
        $pivots = [];
        foreach ($this->included($document) as $resource) {
            if ($resource['type'] !== $type) {
                continue;
            }
            self::assertIsString($resource['id'] ?? null);
            $meta = $resource['meta'] ?? null;
            self::assertIsArray($meta, (string) \json_encode($resource));
            $pivots[$resource['id']] = $meta['pivot'] ?? null;
        }
        \ksort($pivots);

        return $pivots;
    }
}
