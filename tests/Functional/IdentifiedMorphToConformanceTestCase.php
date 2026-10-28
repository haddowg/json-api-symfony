<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The identified MorphTo suite, run identically against the in-memory provider
 * ({@see InMemoryIdentifiedMorphToTest}) and the Doctrine provider
 * ({@see DoctrineIdentifiedMorphToTest}) over
 * {@see App\IdentifiedMorph\IdentifiedMorphFixtures}.
 *
 * A campaign's `target` is declared with `identifiedBy()` and stores its type and id as
 * two plain columns. Agencies are scoped, so agency 2 is hidden, and advertisers are
 * not. The linkage of an advertiser renders from the pair, while an agency's target is
 * loaded and checked first. An include or the related endpoint loads every target
 * through its type's provider.
 */
abstract class IdentifiedMorphToConformanceTestCase extends JsonApiFunctionalTestCase
{
    /**
     * Stores `[$type, $id]` as campaign `$campaignId`'s target.
     */
    abstract protected function storeTarget(int $campaignId, string $type, int $id): void;

    #[Test]
    #[Group('spec:resource-linkage')]
    public function theLinkageRendersEachPairAndHidesTheGuardedTypesHiddenTarget(): void
    {
        $document = $this->document('/campaigns');

        // Agencies are scoped, so agency 2 is checked and hidden. Advertisers aren't, so
        // the pair naming the missing advertiser 99 renders as stored.
        self::assertSame([
            '1' => ['type' => 'advertisers', 'id' => '1'],
            '2' => ['type' => 'agencies', 'id' => '1'],
            '3' => null,
            '4' => null,
            '5' => ['type' => 'advertisers', 'id' => '2'],
            '6' => ['type' => 'agencies', 'id' => '3'],
            '7' => ['type' => 'advertisers', 'id' => '1'],
            '8' => ['type' => 'agencies', 'id' => '1'],
            '9' => ['type' => 'advertisers', 'id' => '99'],
            '10' => ['type' => 'agencies', 'id' => '3'],
        ], $this->linkageByCampaign($document));
        self::assertArrayNotHasKey('included', $document);
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anIncludeLoadsTheTargetsAndLeavesOutTheOnesTheScopeHides(): void
    {
        $document = $this->document('/campaigns?include=target');

        self::assertSame([
            '1' => ['type' => 'advertisers', 'id' => '1'],
            '2' => ['type' => 'agencies', 'id' => '1'],
            '3' => null,
            '4' => null,
            '5' => ['type' => 'advertisers', 'id' => '2'],
            '6' => ['type' => 'agencies', 'id' => '3'],
            '7' => ['type' => 'advertisers', 'id' => '1'],
            '8' => ['type' => 'agencies', 'id' => '1'],
            '9' => null,
            '10' => ['type' => 'agencies', 'id' => '3'],
        ], $this->linkageByCampaign($document));
        self::assertSame(['advertisers:1', 'advertisers:2', 'agencies:1', 'agencies:3'], $this->included($document));

        $single = $this->document('/campaigns/2?include=target');
        self::assertSame(['agencies:1'], $this->included($single));
        self::assertSame('Visible Agency', $this->at($single, 'included', 0, 'attributes', 'name'));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anIncludeContinuesThroughTheTargets(): void
    {
        $document = $this->document('/campaigns?include=target.region');

        self::assertSame(
            ['advertisers:1', 'advertisers:2', 'agencies:1', 'agencies:3', 'regions:1', 'regions:2'],
            $this->included($document),
        );
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theRelatedEndpointLoadsTheTargetThroughItsTypesProvider(): void
    {
        $advertiser = $this->document('/campaigns/1/target');
        self::assertSame('advertisers', $this->at($advertiser, 'data', 'type'));
        self::assertSame('1', $this->at($advertiser, 'data', 'id'));
        self::assertSame('Acme', $this->at($advertiser, 'data', 'attributes', 'name'));

        $agency = $this->document('/campaigns/6/target?include=region');
        self::assertSame('agencies', $this->at($agency, 'data', 'type'));
        self::assertSame('Third Agency', $this->at($agency, 'data', 'attributes', 'name'));
        self::assertSame(['regions:2'], $this->included($agency));

        // Hidden by the scope, and missing altogether.
        foreach (['/campaigns/3/target', '/campaigns/9/target'] as $path) {
            $empty = $this->document($path);
            self::assertArrayHasKey('data', $empty, $path);
            self::assertNull($empty['data'], $path);
        }
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theRelationshipEndpointFollowsTheSameRule(): void
    {
        self::assertSame(['type' => 'agencies', 'id' => '1'], $this->document('/campaigns/2/relationships/target')['data'] ?? null);
        self::assertSame(['type' => 'advertisers', 'id' => '99'], $this->document('/campaigns/9/relationships/target')['data'] ?? null);

        $hidden = $this->document('/campaigns/3/relationships/target');
        self::assertArrayHasKey('data', $hidden);
        self::assertNull($hidden['data']);
    }

    #[Test]
    #[Group('spec:resource-linkage')]
    public function aNullPairIsAnEmptyRelationshipEverywhere(): void
    {
        self::assertSame(['4' => null], $this->linkageByCampaign($this->document('/campaigns/4')));
        self::assertSame(['4' => null], $this->linkageByCampaign($this->document('/campaigns/4?include=target')));

        $related = $this->document('/campaigns/4/target');
        self::assertArrayHasKey('data', $related);
        self::assertNull($related['data']);

        $relationship = $this->document('/campaigns/4/relationships/target');
        self::assertArrayHasKey('data', $relationship);
        self::assertNull($relationship['data']);
    }

    #[Test]
    #[Group('spec:errors')]
    public function aStoredTypeTheRelationDoesNotDeclareIsAServerError(): void
    {
        $this->storeTarget(1, 'regions', 1);

        foreach (['/campaigns/1', '/campaigns?include=target', '/campaigns/1/target'] as $path) {
            $response = $this->handle($path);
            self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode(), $path . ': ' . $response->getContent());
            self::assertSame('500', $this->at($this->decode($response), 'errors', 0, 'status'), $path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function document(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $path . ': ' . $response->getContent());

        return $this->decode($response);
    }

    /**
     * Each campaign's `target` linkage, keyed by campaign id.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    protected function linkageByCampaign(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $linkage = [];
        foreach (\array_is_list($data) ? $data : [$data] as $resource) {
            $id = $this->at($resource, 'id');
            self::assertIsString($id);
            $relationship = $this->at($resource, 'relationships', 'target');
            self::assertIsArray($relationship, 'campaign ' . $id);
            self::assertArrayHasKey('data', $relationship, 'campaign ' . $id);
            $linkage[$id] = $relationship['data'];
        }

        return $linkage;
    }

    /**
     * The `type:id` of every included resource, sorted.
     *
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    protected function included(array $document): array
    {
        $included = $document['included'] ?? [];
        self::assertIsArray($included);

        $members = [];
        foreach ($included as $resource) {
            $type = $this->at($resource, 'type');
            $id = $this->at($resource, 'id');
            self::assertIsString($type);
            self::assertIsString($id);
            $members[] = $type . ':' . $id;
        }
        \sort($members);

        return $members;
    }

    /**
     * The value at `$keys` inside a decoded document, `null` when any level is missing.
     */
    protected function at(mixed $node, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($node)) {
                return null;
            }
            $node = $node[$key] ?? null;
        }

        return $node;
    }
}
