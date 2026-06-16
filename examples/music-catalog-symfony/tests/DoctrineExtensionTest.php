<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface}
 * seam end-to-end (seam 1, backs `custom-data-providers.md` / `doctrine.md`): the
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Query\PublishedAlbumsExtension}
 * scopes every `albums` query the Doctrine provider builds to `published = true`,
 * before the requested criteria apply.
 *
 * The seed has three albums — two published (`1` OK Computer, `2` Dummy) and one
 * unpublished (`3` Unreleased Sessions) — so the scope has a row to hide. The suite
 * asserts the scope holds on the collection, that a requested filter ANDs on top of
 * it (the scope survives), and that an out-of-scope single fetch is a route-scoped
 * `404` while the row still exists in the database.
 */
#[Group('spec:fetching')]
final class DoctrineExtensionTest extends MusicCatalogKernelTestCase
{
    #[Test]
    public function theCollectionIsScopedToPublishedAlbums(): void
    {
        // Album 3 is unpublished, so it never appears — even though it exists in the
        // database (the single-fetch test below proves it is really there). Sorted by
        // title, Dummy (2) precedes OK Computer (1).
        self::assertSame(['2', '1'], $this->ids($this->decode($this->handle('/albums?sort=title'))));
    }

    #[Test]
    #[Group('spec:filtering')]
    public function aRequestedFilterComposesOnTopOfTheScope(): void
    {
        // `WhereHas('tracks')` keeps albums with at least one related track; both
        // published albums (1, 2) have tracks, so the result is the scoped pair — the
        // filter ANDs onto the published scope rather than re-widening it. The
        // unpublished album 3 (no tracks) stays excluded on both counts.
        self::assertSame(['2', '1'], $this->ids($this->decode($this->handle('/albums?filter[tracks]=1&sort=title'))));
    }

    #[Test]
    #[Group('spec:errors')]
    public function aSingleFetchOutsideTheScopeIsNotFound(): void
    {
        // Album 3 exists in the database but `published = false` puts it outside the
        // scope, so the provider yields null and the handler renders a JSON:API 404 —
        // while a published album resolves normally. The same extension pipeline runs
        // for the single fetch, so the scope holds for GET /albums/{id} too.
        self::assertSame(404, $this->handle('/albums/3')->getStatusCode());
        self::assertSame(200, $this->handle('/albums/1')->getStatusCode());
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
