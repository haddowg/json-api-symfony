<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A crew's pivot relation renders the same `meta.pivot` when it is reached through a
 * nested include as when the crew is the primary resource: on each member of the crew's
 * linkage and on each included member. Ships belong to a crew and fleets hold ships, so
 * `cp-ships?include=crew.members` reaches `members` one level deep and
 * `cp-fleets?include=ships.crew.members` two levels deep. Crew 1 ("Reds") holds Ada and
 * Linus, crew 2 ("Blues") Ada and Grace, and `badge` is hidden from Linus.
 *
 * A ship's own `members` renders its data without an include, so its pivot renders
 * wherever a ship does. A fleet's `units` and `vessels` hold ships and crews, so a
 * `members` include from them reaches both kinds of pivot through a polymorphic page.
 */
abstract class NestedPivotIncludeConformanceTestCase extends JsonApiFunctionalTestCase
{
    protected const array REDS = ['1' => ['role' => 'Reds:Ada', 'badge' => 'ADA'], '3' => ['role' => 'Reds:Linus']];
    protected const array BLUES = ['1' => ['role' => 'Blues:Ada', 'badge' => 'ADA'], '2' => ['role' => 'Blues:Grace', 'badge' => 'GRACE']];
    protected const array ARK = ['3' => ['post' => 'Ark:Linus']];
    /**
     * The pivot relations of each type, whose linkage members carry `meta.pivot` wherever
     * their data renders.
     */
    protected const array PIVOT_RELATIONS = ['cp-crews' => ['members', 'staff'], 'cp-ships' => ['members'], 'cp-fleets' => ['hands']];

    protected const array BARK = ['1' => ['post' => 'Bark:Ada'], '2' => ['post' => 'Bark:Grace']];

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

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aPolymorphicRelatedPageRendersThePivotOfItsIncludes(): void
    {
        $document = $this->get('/cp-fleets/1/units?include=members');

        self::assertSame(self::ARK, $this->pivots($this->dataLinkage($document, 'cp-ships', '1', 'members')));
        self::assertSame(self::BARK, $this->pivots($this->dataLinkage($document, 'cp-ships', '2', 'members')));
        self::assertSame(self::REDS, $this->pivots($this->dataLinkage($document, 'cp-crews', '1', 'members')));
        self::assertSame(['1', '2', '3'], \array_map('strval', \array_keys($this->includedPivots($document, 'cp-members'))));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anIdentifiedPolymorphicRelatedPageRendersThePivotOfItsIncludes(): void
    {
        $document = $this->get('/cp-fleets/1/vessels?include=members');

        self::assertSame(self::BARK, $this->pivots($this->dataLinkage($document, 'cp-ships', '2', 'members')));
        self::assertSame(self::REDS, $this->pivots($this->dataLinkage($document, 'cp-crews', '1', 'members')));
        self::assertSame(self::REDS['3'], $this->includedPivots($document, 'cp-members')['3'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aPivotBelowAPolymorphicIncludeRendersItsPivot(): void
    {
        foreach (['/cp-fleets/1?include=units.members', '/cp-fleets?include=units.members'] as $path) {
            $document = $this->get($path);

            self::assertSame(self::ARK, $this->pivots($this->includedLinkage($document, 'cp-ships', '1', 'members')), $path);
            self::assertSame(self::BARK, $this->pivots($this->includedLinkage($document, 'cp-ships', '2', 'members')), $path);
            self::assertSame(self::REDS, $this->pivots($this->includedLinkage($document, 'cp-crews', '1', 'members')), $path);
        }

        $document = $this->get('/cp-fleets/1?include=vessels.members');
        self::assertSame(self::BARK, $this->pivots($this->includedLinkage($document, 'cp-ships', '2', 'members')));
        self::assertSame(self::REDS, $this->pivots($this->includedLinkage($document, 'cp-crews', '1', 'members')));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aWithDataPivotRendersOnThePrimaryLinkage(): void
    {
        self::assertSame(self::BARK, $this->pivots($this->linkage($this->get('/cp-ships/2'), 'members')));

        $document = $this->get('/cp-ships');
        self::assertSame(self::ARK, $this->pivots($this->dataLinkage($document, 'cp-ships', '1', 'members')));
        self::assertSame(self::BARK, $this->pivots($this->dataLinkage($document, 'cp-ships', '2', 'members')));
        self::assertSame([], $this->pivots($this->dataLinkage($document, 'cp-ships', '3', 'members')));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aWithDataPivotRendersBelowThePrimaryLevel(): void
    {
        $document = $this->get('/cp-fleets/1?include=ships,flagship');

        self::assertSame(self::ARK, $this->pivots($this->includedLinkage($document, 'cp-ships', '1', 'members')));
        self::assertSame(self::BARK, $this->pivots($this->includedLinkage($document, 'cp-ships', '2', 'members')));
        self::assertSame([], $this->pivots($this->includedLinkage($document, 'cp-ships', '3', 'members')));
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anAtomicUpdateResultRendersAWithDataPivot(): void
    {
        $result = $this->atomicResult(['op' => 'update', 'ref' => ['type' => 'cp-ships', 'id' => '2'], 'data' => [
            'type' => 'cp-ships',
            'id' => '2',
            'attributes' => ['name' => 'Barque'],
        ]]);

        self::assertSame(['1' => ['post' => 'Barque:Ada'], '2' => ['post' => 'Barque:Grace']], $this->pivots($this->linkage($result, 'members')));
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anAtomicAddResultRendersAWithDataPivot(): void
    {
        $result = $this->atomicResult(['op' => 'add', 'data' => [
            'type' => 'cp-ships',
            'id' => '9',
            'attributes' => ['name' => 'Dory'],
            'relationships' => ['members' => ['data' => [['type' => 'cp-members', 'id' => '3']]]],
        ]]);

        self::assertSame(['3' => ['post' => 'Dory:Linus']], $this->pivots($this->linkage($result, 'members')));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aPivotMemberKeepsItsLinksWhereverItRenders(): void
    {
        // Members are routed under `crew-members`, render no self link and link `commend`.
        foreach ([
            '/cp-crews/2/members',
            '/cp-crews/2?include=members',
            '/cp-ships/2?include=crew.members',
            '/cp-fleets/1?include=ships.crew.members',
            '/cp-fleets/1/units?include=members',
            '/cp-fleets/1/vessels?include=members',
        ] as $path) {
            $document = $this->get($path);
            $data = $document['data'] ?? null;
            self::assertIsArray($data);
            $resources = [...(\array_is_list($data) ? $data : [$data]), ...$this->included($document)];

            $members = 0;
            foreach ($resources as $resource) {
                self::assertIsArray($resource);
                if (($resource['type'] ?? null) !== 'cp-members') {
                    continue;
                }
                ++$members;
                self::assertIsString($resource['id'] ?? null);
                self::assertSame(
                    ['commend' => ['href' => 'https://example.test/crew-members/' . $resource['id'] . '/-actions/commend']],
                    $resource['links'] ?? null,
                    $path,
                );
            }
            self::assertGreaterThan(0, $members, $path);
        }
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anActionResponseRendersThePivotOfItsInclude(): void
    {
        $document = $this->action('/cp-crews/2/-actions/muster?include=members');

        self::assertSame(self::BLUES, $this->pivots($this->linkage($document, 'members')));
        self::assertSame(self::BLUES, $this->includedPivots($document, 'cp-members'));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anActionResponseRendersThePivotOfANestedInclude(): void
    {
        $document = $this->action('/cp-ships/2/-actions/muster?include=crew.members');

        self::assertSame(self::BARK, $this->pivots($this->linkage($document, 'members')));
        self::assertSame(self::BLUES, $this->pivots($this->includedLinkage($document, 'cp-crews', '2', 'members')));
        self::assertSame(self::BLUES, $this->includedPivots($document, 'cp-members'));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aCollectionActionResponseRendersEachPivot(): void
    {
        $document = $this->action('/cp-ships/-actions/roll?include=crew.members');

        self::assertSame(self::ARK, $this->pivots($this->dataLinkage($document, 'cp-ships', '1', 'members')));
        self::assertSame(self::BARK, $this->pivots($this->dataLinkage($document, 'cp-ships', '2', 'members')));
        self::assertSame([], $this->pivots($this->dataLinkage($document, 'cp-ships', '3', 'members')));
        self::assertSame(self::REDS, $this->pivots($this->includedLinkage($document, 'cp-crews', '1', 'members')));
        self::assertSame(self::BLUES, $this->pivots($this->includedLinkage($document, 'cp-crews', '2', 'members')));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aMixedTypeActionResponseRendersEachTypesPivot(): void
    {
        $document = $this->action('/cp-ships/-actions/mix?include=members');

        self::assertSame(self::BARK, $this->pivots($this->dataLinkage($document, 'cp-ships', '2', 'members')));
        self::assertSame(self::REDS, $this->pivots($this->dataLinkage($document, 'cp-crews', '1', 'members')));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anAcceptedActionsJobResourceRendersItsPivot(): void
    {
        $document = $this->action('/cp-crews/1/-actions/queue?include=members', 202);

        self::assertSame(self::REDS, $this->pivots($this->linkage($document, 'members')));
        self::assertSame(self::REDS, $this->includedPivots($document, 'cp-members'));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aSparseFieldsetOnAnActionResponseKeepsThePivot(): void
    {
        $document = $this->action('/cp-ships/2/-actions/muster?include=crew.members&fields[cp-crews]=members&fields[cp-members]=name');

        self::assertSame(self::BLUES, $this->pivots($this->includedLinkage($document, 'cp-crews', '2', 'members')));
        self::assertSame(self::BLUES, $this->includedPivots($document, 'cp-members'));
        foreach ($this->included($document) as $resource) {
            if ($resource['type'] === 'cp-members') {
                self::assertSame(['name'], \array_keys((array) ($resource['attributes'] ?? [])));
            }
        }

        // A ship's `crew` is declared notSparseField().
        $document = $this->action('/cp-ships/2/-actions/muster?include=crew.members&fields[cp-ships]=name');
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);
        self::assertSame(['crew'], \array_keys($relationships));
        self::assertSame(self::BLUES, $this->pivots($this->includedLinkage($document, 'cp-crews', '2', 'members')));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aNotSparsePivotRelationStaysOutsideTheFieldset(): void
    {
        // A fleet's `hands` is declared notSparseField().
        foreach ([$this->get('/cp-fleets/1?include=hands&fields[cp-fleets]=name'), $this->action('/cp-fleets/1/-actions/muster?include=hands&fields[cp-fleets]=name')] as $document) {
            $data = $document['data'] ?? null;
            self::assertIsArray($data);
            $relationships = $data['relationships'] ?? null;
            self::assertIsArray($relationships);
            self::assertSame(['hands'], \array_keys($relationships));
            self::assertSame(['2' => ['rank' => 'Home:Grace']], $this->pivots($this->linkage($document, 'hands')));
            self::assertSame(['2' => ['rank' => 'Home:Grace']], $this->includedPivots($document, 'cp-members'));
        }
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function everyRenderedPivotLinkageCarriesItsPivot(): void
    {
        // A crew's `staff` reads its members whatever was included, so its linkage renders
        // with no include, and in memory every relation's linkage does.
        foreach ([
            '/cp-crews/1',
            '/cp-crews/1?include=members',
            '/cp-crews',
            '/cp-crews?include=members',
            '/cp-crews/2?fields[cp-crews]=staff',
            '/cp-crews/2?fields[cp-crews]=name,staff&include=members',
            '/cp-ships?include=crew',
            '/cp-ships/2?include=crew',
            '/cp-ships?include=crew&fields[cp-crews]=staff',
            '/cp-fleets/1?include=ships.crew,flagship.crew',
            '/cp-fleets?include=ships.crew',
            '/cp-fleets/1/units',
            '/cp-fleets/1/vessels',
            '/cp-fleets/1?include=units,vessels',
            '/cp-ships/2/crew',
            '/cp-ships/2/crew?include=members',
            '/cp-fleets/1/ships?include=crew',
        ] as $path) {
            self::assertGreaterThan(0, $this->assertEveryLinkageCarriesItsPivot($this->get($path), $path), $path);
        }
    }

    #[Test]
    #[Group('spec:crud')]
    public function aWriteResponseCarriesThePivotOfEveryLinkageItRenders(): void
    {
        $response = $this->handle('/cp-crews/2', 'PATCH', ['data' => [
            'type' => 'cp-crews',
            'id' => '2',
            'attributes' => ['name' => 'Navy'],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertGreaterThan(0, $this->assertEveryLinkageCarriesItsPivot($this->decode($response), 'update'));

        // The members a create writes are loaded on the new crew, so their linkage renders.
        $response = $this->handle('/cp-crews', 'POST', ['data' => [
            'type' => 'cp-crews',
            'id' => '7',
            'attributes' => ['name' => 'Greens'],
            'relationships' => ['members' => ['data' => [['type' => 'cp-members', 'id' => '1']]]],
        ]]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);
        self::assertGreaterThan(0, $this->assertEveryLinkageCarriesItsPivot($document, 'create'));
        self::assertSame(['1' => ['role' => 'Greens:Ada', 'badge' => 'ADA']], $this->pivots($this->linkage($document, 'members')));

        $response = $this->handle('/cp-ships/2?include=crew', 'PATCH', ['data' => [
            'type' => 'cp-ships',
            'id' => '2',
            'attributes' => ['name' => 'Barque'],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertGreaterThan(0, $this->assertEveryLinkageCarriesItsPivot($this->decode($response), 'nested update'));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anActionResponseCarriesThePivotOfEveryLinkageItRenders(): void
    {
        foreach ([
            ['/cp-crews/2/-actions/muster', 200],
            ['/cp-ships/2/-actions/muster?include=crew', 200],
            ['/cp-ships/-actions/roll?include=crew', 200],
            ['/cp-ships/-actions/mix', 200],
            ['/cp-crews/1/-actions/queue', 202],
        ] as [$path, $status]) {
            self::assertGreaterThan(0, $this->assertEveryLinkageCarriesItsPivot($this->action($path, $status), $path), $path);
        }
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anAtomicResultCarriesThePivotOfEveryLinkageItRenders(): void
    {
        $result = $this->atomicResult(['op' => 'update', 'ref' => ['type' => 'cp-crews', 'id' => '2'], 'data' => [
            'type' => 'cp-crews',
            'id' => '2',
            'attributes' => ['name' => 'Navy'],
        ]]);
        self::assertGreaterThan(0, $this->assertEveryLinkageCarriesItsPivot($result, 'atomic update'));

        $result = $this->atomicResult(['op' => 'add', 'data' => [
            'type' => 'cp-crews',
            'id' => '8',
            'attributes' => ['name' => 'Golds'],
            'relationships' => ['members' => ['data' => [['type' => 'cp-members', 'id' => '2']]]],
        ]]);
        self::assertGreaterThan(0, $this->assertEveryLinkageCarriesItsPivot($result, 'atomic add'));
        self::assertSame(['2' => ['role' => 'Golds:Grace', 'badge' => 'GRACE']], $this->pivots($this->linkage($result, 'members')));
    }

    /**
     * The document a custom action answers with.
     *
     * @return array<string, mixed>
     */
    protected function action(string $path, int $status = 200): array
    {
        $response = $this->handle($path, 'POST');
        self::assertSame($status, $response->getStatusCode(), $path . ': ' . $response->getContent());

        return $this->decode($response);
    }

    /**
     * The first result of an Atomic Operations batch holding `$operation`.
     *
     * @param array<string, mixed> $operation
     *
     * @return array<string, mixed>
     */
    protected function atomicResult(array $operation): array
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $media = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';
        $request = Request::create('/operations', 'POST', server: ['CONTENT_TYPE' => $media, 'HTTP_ACCEPT' => $media], content: \json_encode(['atomic:operations' => [$operation]], \JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $this->restoreHandlers();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $results = $this->decode($response)['atomic:results'] ?? null;
        self::assertIsArray($results);
        $result = $results[0] ?? null;
        self::assertIsArray($result);
        /** @var array<string, mixed> $result */

        return $result;
    }

    /**
     * The linkage of `$relation` on the primary resource `$type` `$id` of a collection
     * document, as a document of its own.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    protected function dataLinkage(array $document, string $type, string $id, string $relation): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            if (($resource['type'] ?? null) === $type && ($resource['id'] ?? null) === $id) {
                return $this->linkage(['data' => $resource], $relation);
            }
        }

        self::fail(\sprintf('%s %s is not primary data: %s', $type, $id, \json_encode($document)));
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

    /**
     * Asserts that every member of every pivot relation's linkage the document renders,
     * on its primary data and on its included resources, carries `meta.pivot`. Returns
     * how many linkage members it checked.
     *
     * @param array<string, mixed> $document
     */
    protected function assertEveryLinkageCarriesItsPivot(array $document, string $context): int
    {
        $data = $document['data'] ?? null;
        $resources = \is_array($data) ? (\array_is_list($data) ? $data : [$data]) : [];
        $checked = 0;
        foreach ([...$resources, ...$this->included($document)] as $resource) {
            self::assertIsArray($resource);
            $relationships = $resource['relationships'] ?? [];
            self::assertIsArray($relationships);
            $type = $resource['type'] ?? null;
            self::assertIsString($type);
            foreach (static::PIVOT_RELATIONS[$type] ?? [] as $relation) {
                $relationship = $relationships[$relation] ?? null;
                if (!\is_array($relationship) || !\array_key_exists('data', $relationship)) {
                    continue;
                }
                self::assertIsArray($relationship['data']);
                foreach ($relationship['data'] as $member) {
                    self::assertIsArray($member);
                    $meta = $member['meta'] ?? null;
                    $where = \sprintf('%s: %s %s %s member %s', $context, \json_encode($type), \json_encode($resource['id'] ?? null), $relation, \json_encode($member));
                    self::assertIsArray($meta, $where);
                    self::assertIsArray($meta['pivot'] ?? null, $where);
                    self::assertNotSame([], $meta['pivot'], $where);
                    ++$checked;
                }
            }
        }

        return $checked;
    }
}
