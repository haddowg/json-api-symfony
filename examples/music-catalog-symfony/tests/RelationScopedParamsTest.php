<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Relation-scoped filters/sorts (bundle ADR 0044, backs `relationships.md`): a
 * RELATION may declare extra `filter`/`sort` keys that augment ONLY its
 * related-collection endpoint `GET /{type}/{id}/{rel}` — not the primary
 * collection of the related type.
 *
 * The witness is `albums.tracks`: the relation declares
 * `->withFilters(Where::make('longerThan', 'length_seconds', '>'))` and
 * `->withSorts(SortByField::make('duration', 'length_seconds'))` — naming the
 * related `Track` entity's `length_seconds` column, which is NEITHER a declared
 * filter NOR a declared sort on the `tracks` resource. So:
 *
 *  - both keys work on `GET /albums/1/tracks`, where the relation's vocabulary is
 *    merged on top of the `tracks` resource's own (the related type's `explicit`
 *    default filter still hides the explicit track, AND a `tracks` key like
 *    `filter[title]` composes with the relation's `filter[longerThan]`);
 *  - both keys are ABSENT from the primary `/tracks` collection — `GET /tracks` with
 *    either key is a `400`, because only the `tracks` resource's own vocabulary
 *    applies there. That endpoint-scoping is the load-bearing guarantee.
 *
 * Album 1 (OK Computer) holds tracks 1 (Airbag, 284s), 2 (Paranoid Android, 383s,
 * explicit) and 3 (Exit Music, 264s). The `tracks` `explicit` default filter hides
 * track 2, so the related collection's visible members are tracks 1 and 3.
 */
#[Group('spec:fetching-relationships')]
final class RelationScopedParamsTest extends MusicCatalogKernelTestCase
{
    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aRelationScopedSortOrdersTheRelatedCollection(): void
    {
        // sort=duration (ascending length_seconds): Exit Music (264s, track 3)
        // precedes Airbag (284s, track 1). Descending flips it. `duration` is a
        // relation-only sort key — the related `tracks` resource never declares it.
        self::assertSame(['3', '1'], $this->ids($this->fetch('/albums/1/tracks?sort=duration')));
        self::assertSame(['1', '3'], $this->ids($this->fetch('/albums/1/tracks?sort=-duration')));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aRelationScopedFilterNarrowsTheRelatedCollection(): void
    {
        // filter[longerThan]=270 keeps tracks whose length_seconds exceeds 270:
        // track 1 (284s) qualifies, track 3 (264s) is excluded. Track 2 (383s) would
        // qualify but is hidden by the related type's `explicit` default filter — so
        // the relation filter and the related resource's own filter both apply.
        self::assertSame(['1'], $this->ids($this->fetch('/albums/1/tracks?filter[longerThan]=270')));

        // A value the relation filter rejects everything below: longerThan=400 leaves
        // the collection empty (track 1 is 284s, track 3 is 264s).
        self::assertSame([], $this->ids($this->fetch('/albums/1/tracks?filter[longerThan]=400')));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function theRelationVocabularyMergesWithTheRelatedResourceVocabulary(): void
    {
        // The relation's `filter[longerThan]` composes with the related `tracks`
        // resource's own `like` `filter[title]`: both must hold. "air" matches
        // Airbag (track 1, 284s) only, and 284 > 200, so track 1 survives; a
        // longerThan above its length excludes it — proving the two vocabularies AND
        // together on the related endpoint.
        self::assertSame(['1'], $this->ids($this->fetch('/albums/1/tracks?filter[title]=air&filter[longerThan]=200')));
        self::assertSame([], $this->ids($this->fetch('/albums/1/tracks?filter[title]=air&filter[longerThan]=300')));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function theRelationScopedFilterIsNotRecognizedOnThePrimaryCollection(): void
    {
        // The headline scoping guarantee: `filter[longerThan]` is declared on the
        // RELATION, so the primary `/tracks` collection — which knows only the
        // `tracks` resource's own vocabulary — rejects it with a 400.
        $error = $this->errorOn('/tracks?filter[longerThan]=270', 400);

        self::assertSame('400', $error['status'] ?? null);
        self::assertSame(['parameter' => 'filter[longerThan]'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function theRelationScopedSortIsNotRecognizedOnThePrimaryCollection(): void
    {
        // Symmetrically, `sort=duration` is relation-scoped, so the primary `/tracks`
        // collection 400s on it.
        $error = $this->errorOn('/tracks?sort=duration', 400);

        self::assertSame('400', $error['status'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function anUnrecognizedKeyStill400sOnTheRelatedEndpoint(): void
    {
        // The merge widens the vocabulary, it does not open it: a key in NEITHER the
        // relation's nor the related resource's vocabulary still 400s on the related
        // endpoint, exactly as on a primary collection.
        $this->errorOn('/albums/1/tracks?filter[nope]=x', 400);
        $this->errorOn('/albums/1/tracks?sort=nope', 400);
    }

    // --- helpers -------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $path): array
    {
        $response = $this->handle($path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function errorOn(string $path, int $status): array
    {
        $response = $this->handle($path);

        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->decode($response);
        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $first = $errors[0] ?? null;
        self::assertIsArray($first);

        /** @var array<string, mixed> $first */
        return $first;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function ids(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }
}
