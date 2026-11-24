<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\MorphKeysFixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A polymorphic id stored as the target's storage key or its wire id, for a plain integer
 * id, an encoded one and an encoded one no scope narrows, asserted identically against the
 * in-memory provider ({@see InMemoryMorphKeysTest}) and the Doctrine provider
 * ({@see DoctrineMorphKeysTest}) over the rows {@see MorphKeysFixtures} describes. The
 * filter, the linkage, the related read and the include agree on every row.
 */
#[Group('spec:fetching-filtering')]
abstract class MorphKeysConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    public function aMorphToMatchesAPlainIdTheScopeAdmits(): void
    {
        self::assertSame(['1'], $this->noteIds('filter[subject][type]=mkCounters'));
        self::assertSame(['1'], $this->noteIds('filter[subject][type]=mkCounters&filter[subject][id]=1'));
        self::assertSame([], $this->noteIds('filter[subject][type]=mkCounters&filter[subject][id]=2'));
    }

    #[Test]
    public function aMorphToMatchesAnEncodedIdStoredInEitherForm(): void
    {
        self::assertSame(['3', '4'], $this->noteIds('filter[subject][type]=mkBadges'));
        self::assertSame(['3'], $this->noteIds('filter[subject][type]=mkBadges&filter[subject][id]=b-1'));
        self::assertSame(['4'], $this->noteIds('filter[subject][type]=mkBadges&filter[subject][id]=b-2'));
        self::assertSame(['3', '4'], $this->noteIds('filter[subject][type]=mkBadges&filter[subject][id]=b-1,b-2,b-3,b-4'));
        self::assertSame([], $this->noteIds('filter[subject][type]=mkBadges&filter[subject][id]=b-3,b-4'));
    }

    #[Test]
    public function anUnscopedTypeMatchesEitherFormToo(): void
    {
        self::assertSame(['7', '8'], $this->noteIds('filter[subject][type]=mkTokens'));
        self::assertSame(['7'], $this->noteIds('filter[subject][type]=mkTokens&filter[subject][id]=t-1'));
        self::assertSame(['8'], $this->noteIds('filter[subject][type]=mkTokens&filter[subject][id]=t-2'));
    }

    #[Test]
    public function aMorphToManyMatchesALinkRowInEitherForm(): void
    {
        self::assertSame(['1', '4'], $this->noteIds('filter[pinned][type]=mkBadges'));
        self::assertSame(['1'], $this->noteIds('filter[pinned][type]=mkBadges&filter[pinned][id]=b-2'));
        self::assertSame(['4'], $this->noteIds('filter[pinned][type]=mkBadges&filter[pinned][id]=b-1'));
        self::assertSame([], $this->noteIds('filter[pinned][type]=mkBadges&filter[pinned][id]=b-3'));
        self::assertSame(['3'], $this->noteIds('filter[pinned][type]=mkCounters'));
        self::assertSame(['7'], $this->noteIds('filter[pinned][type]=mkTokens&filter[pinned][id]=t-2'));
    }

    #[Test]
    public function theLinkageRendersTheWireIdWhicheverFormIsStored(): void
    {
        $linkage = [];
        foreach ($this->rows('/mkNotes') as $note) {
            $subject = $note['relationships']['subject'] ?? null;
            self::assertIsArray($subject);
            $linkage[$note['id']] = $subject['data'] ?? null;
        }

        self::assertSame(['type' => 'mkCounters', 'id' => '1'], $linkage['1'] ?? null);
        self::assertSame(['type' => 'mkBadges', 'id' => 'b-1'], $linkage['3'] ?? null);
        self::assertSame(['type' => 'mkBadges', 'id' => 'b-2'], $linkage['4'] ?? null);
        self::assertSame(['type' => 'mkTokens', 'id' => 't-1'], $linkage['7'] ?? null);
        self::assertSame(['type' => 'mkTokens', 'id' => 't-2'], $linkage['8'] ?? null);
    }

    #[Test]
    public function theRelatedReadAgreesWithTheFilter(): void
    {
        self::assertSame('mkCounters:1:one', $this->related(1));
        self::assertNull($this->related(2));
        self::assertSame('mkBadges:b-1:gold', $this->related(3));
        self::assertSame('mkBadges:b-2:silver', $this->related(4));
        self::assertNull($this->related(5));
        self::assertNull($this->related(6));
        self::assertSame('mkTokens:t-1:red', $this->related(7));
        self::assertSame('mkTokens:t-2:blue', $this->related(8));
    }

    #[Test]
    public function theIncludeAgreesWithTheFilter(): void
    {
        $document = $this->document('/mkNotes?include=subject&filter[subject][type]=mkBadges');
        self::assertSame(['mkBadges:b-1', 'mkBadges:b-2'], $this->keys($document['included'] ?? []));

        $document = $this->document('/mkNotes?include=subject');
        self::assertSame(['mkBadges:b-1', 'mkBadges:b-2', 'mkCounters:1', 'mkTokens:t-1', 'mkTokens:t-2'], $this->keys($document['included'] ?? []));
    }

    /**
     * @return list<string>
     */
    private function noteIds(string $query): array
    {
        $ids = \array_map(static fn(array $row): string => $row['id'], $this->rows('/mkNotes?' . $query));
        \sort($ids);

        return $ids;
    }

    /**
     * The note's related subject as `type:id:name`, or `null` when there is none.
     */
    private function related(int $note): ?string
    {
        $data = $this->document('/mkNotes/' . $note . '/subject')['data'] ?? null;
        if ($data === null) {
            return null;
        }
        self::assertIsArray($data);
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);

        $parts = [$data['type'] ?? null, $data['id'] ?? null, $attributes['name'] ?? null];
        self::assertContainsOnlyString($parts);

        return \implode(':', $parts);
    }

    /**
     * @return list<array{id: string, relationships: array<string, mixed>}>
     */
    private function rows(string $path): array
    {
        $rows = $this->document($path)['data'] ?? null;
        self::assertIsArray($rows);

        /** @var list<array{id: string, relationships: array<string, mixed>}> $rows */
        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function document(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), $path . ': ' . $response->getContent());

        return $this->decode($response);
    }

    /**
     * @param mixed $resources
     *
     * @return list<string>
     */
    private function keys(mixed $resources): array
    {
        self::assertIsArray($resources);
        $keys = [];
        foreach ($resources as $resource) {
            self::assertIsArray($resource);
            $keys[] = (\is_string($resource['type'] ?? null) ? $resource['type'] : '') . ':' . (\is_string($resource['id'] ?? null) ? $resource['id'] : '');
        }
        \sort($keys);

        return $keys;
    }
}
