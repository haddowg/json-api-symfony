<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\RelatedProviderBatch\ProviderCalls;
use PHPUnit\Framework\Attributes\Test;

/**
 * A run's progress and advertisers come from their own provider, which answers a whole
 * page of runs in one batch; the run holds neither. Its notes come from a provider that
 * can't batch, so they load one run at a time.
 *
 * The fixture: run 1 has progress p1 and advertisers 1 and 2, run 2 progress p2 and
 * advertiser 3, run 3 neither. Run 1 has note 1, run 2 notes 2 and 3.
 */
abstract class RelatedProviderBatchConformanceTestCase extends JsonApiFunctionalTestCase
{
    protected function afterBoot(): void
    {
        $this->seed();
        ProviderCalls::reset();
    }

    protected function seed(): void {}

    #[Test]
    public function anIncludeReadsEachRelationInOneBatchPerPage(): void
    {
        $document = $this->read('/runs?include=progress,advertisers');

        self::assertSame(1, ProviderCalls::count('batch', 'runProgress'));
        self::assertSame(1, ProviderCalls::count('batch', 'runAdvertisers'));
        self::assertSame(0, ProviderCalls::count('related', 'runProgress'));
        self::assertSame(0, ProviderCalls::count('related', 'runAdvertisers'));

        $this->assertLinkage($document);
        self::assertSame(['p1', 'p2'], $this->includedIds($document, 'runProgress'));
        self::assertSame(['1', '2', '3'], $this->includedIds($document, 'runAdvertisers'));
        self::assertSame(40, $this->includedAttribute($document, 'runProgress', 'p1', 'percent'));
    }

    #[Test]
    public function linkageDataReadsEachRelationInOneBatchPerPage(): void
    {
        $document = $this->read('/runs');

        self::assertSame(1, ProviderCalls::count('batch', 'runProgress'));
        self::assertSame(1, ProviderCalls::count('batch', 'runAdvertisers'));
        self::assertSame(0, ProviderCalls::count('related', 'runAdvertisers'));

        $this->assertLinkage($document);
        self::assertArrayNotHasKey('included', $document);
    }

    #[Test]
    public function aSingleParentIsAOneElementBatch(): void
    {
        $document = $this->read('/runs/2?include=progress,advertisers');

        self::assertSame(1, ProviderCalls::count('batch', 'runProgress'));
        self::assertSame(1, ProviderCalls::count('batch', 'runAdvertisers'));
        self::assertSame(['p2'], $this->includedIds($document, 'runProgress'));
        self::assertSame(['3'], $this->includedIds($document, 'runAdvertisers'));
    }

    #[Test]
    public function theRelatedEndpointRendersTheBatchedTarget(): void
    {
        $document = $this->read('/runs/1/progress');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('runProgress', $data['type'] ?? null);
        self::assertSame('p1', $data['id'] ?? null);
        self::assertSame(1, ProviderCalls::count('batch', 'runProgress'));

        $empty = $this->read('/runs/3/progress');
        self::assertArrayHasKey('data', $empty);
        self::assertNull($empty['data']);
    }

    #[Test]
    public function theRelationshipEndpointRendersTheBatchedTarget(): void
    {
        $document = $this->read('/runs/2/relationships/progress');

        self::assertSame(['type' => 'runProgress', 'id' => 'p2'], $document['data'] ?? null);
        self::assertSame(1, ProviderCalls::count('batch', 'runProgress'));

        $empty = $this->read('/runs/3/relationships/progress');
        self::assertArrayHasKey('data', $empty);
        self::assertNull($empty['data']);
    }

    /**
     * The filter runs against each target the batch supplies, and the page is still
     * read in one batch.
     */
    #[Test]
    public function aRelatedQueryFilterMatchesTheBatchedTargets(): void
    {
        $response = $this->handle(
            '/runs?include=progress&relatedQuery[progress][filter][percent]=100',
            extraServer: ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"'],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);

        self::assertNull($this->relationship($document, '1', 'progress')['data'] ?? null);
        self::assertSame(['type' => 'runProgress', 'id' => 'p2'], $this->relationship($document, '2', 'progress')['data'] ?? null);
        self::assertNull($this->relationship($document, '3', 'progress')['data'] ?? null);
        self::assertSame(['p2'], $this->includedIds($document, 'runProgress'));
        self::assertSame(1, ProviderCalls::count('batch', 'runProgress'));
    }

    #[Test]
    public function theEndpointsFilterTheBatchedTarget(): void
    {
        $excluded = $this->read('/runs/1/progress?filter[percent]=100');
        self::assertArrayHasKey('data', $excluded);
        self::assertNull($excluded['data']);

        $related = $this->read('/runs/2/progress?filter[percent]=100');
        self::assertSame('p2', \is_array($related['data'] ?? null) ? ($related['data']['id'] ?? null) : null);

        ProviderCalls::reset();
        $relationship = $this->read('/runs/2/relationships/progress?filter[percent]=100');
        self::assertSame(['type' => 'runProgress', 'id' => 'p2'], $relationship['data'] ?? null);
        self::assertSame(1, ProviderCalls::count('batch', 'runProgress'));

        $excluded = $this->read('/runs/1/relationships/progress?filter[percent]=100');
        self::assertArrayHasKey('data', $excluded);
        self::assertNull($excluded['data']);
    }

    #[Test]
    public function aProviderThatCannotBatchIsReadPerParent(): void
    {
        $document = $this->read('/runs?include=notes');

        self::assertSame(3, ProviderCalls::count('related', 'runNotes'));
        self::assertSame(1, ProviderCalls::count('batch', 'runNotes'));
        self::assertSame(['1'], $this->linkageIds($document, '1', 'notes'));
        self::assertSame(['2', '3'], $this->linkageIds($document, '2', 'notes'));
        self::assertSame([], $this->linkageIds($document, '3', 'notes'));
        self::assertSame(['1', '2', '3'], $this->includedIds($document, 'runNotes'));
    }

    /**
     * @param array<string, mixed> $document
     */
    private function assertLinkage(array $document): void
    {
        self::assertSame(['type' => 'runProgress', 'id' => 'p1'], $this->relationship($document, '1', 'progress')['data'] ?? null);
        self::assertSame(['type' => 'runProgress', 'id' => 'p2'], $this->relationship($document, '2', 'progress')['data'] ?? null);
        self::assertArrayHasKey('data', $this->relationship($document, '3', 'progress'));
        self::assertNull($this->relationship($document, '3', 'progress')['data']);

        self::assertSame(['1', '2'], $this->linkageIds($document, '1', 'advertisers'));
        self::assertSame(['3'], $this->linkageIds($document, '2', 'advertisers'));
        self::assertSame([], $this->linkageIds($document, '3', 'advertisers'));
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function relationship(array $document, string $id, string $name): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        foreach (\array_is_list($data) ? $data : [$data] as $resource) {
            if (\is_array($resource) && ($resource['id'] ?? null) === $id) {
                $relationships = $resource['relationships'] ?? null;
                self::assertIsArray($relationships);
                $relationship = $relationships[$name] ?? null;
                self::assertIsArray($relationship, 'relationship ' . $name . ' of ' . $id);

                $members = [];
                foreach ($relationship as $key => $value) {
                    $members[(string) $key] = $value;
                }

                return $members;
            }
        }

        self::fail('no resource ' . $id);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function linkageIds(array $document, string $id, string $name): array
    {
        $data = $this->relationship($document, $id, $name)['data'] ?? null;
        self::assertIsArray($data);

        return \array_values(\array_map(static fn(mixed $identifier): string => \is_array($identifier) && \is_string($identifier['id'] ?? null) ? $identifier['id'] : '', $data));
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function includedIds(array $document, string $type): array
    {
        $ids = [];
        foreach ($this->included($document) as $resource) {
            if (($resource['type'] ?? null) === $type && \is_string($resource['id'] ?? null)) {
                $ids[] = $resource['id'];
            }
        }
        \sort($ids);

        return $ids;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function includedAttribute(array $document, string $type, string $id, string $attribute): mixed
    {
        foreach ($this->included($document) as $resource) {
            if (($resource['type'] ?? null) === $type && ($resource['id'] ?? null) === $id) {
                $attributes = $resource['attributes'] ?? null;

                return \is_array($attributes) ? ($attributes[$attribute] ?? null) : null;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<array<mixed>>
     */
    private function included(array $document): array
    {
        $included = $document['included'] ?? [];
        self::assertIsArray($included);

        return \array_values(\array_filter($included, \is_array(...)));
    }
}
