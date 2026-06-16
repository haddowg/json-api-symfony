<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The CRUD-write acceptance suite (backs `data-layer.md`): create / update / delete
 * end to end through the bundle's single generic `CrudOperationHandler` over the
 * reference Doctrine persister — `201` + `Location`, `200`, `204`, and the
 * managed-update + 404 behaviours.
 *
 * Creates ride `playlists`: its client-generated UUID id is echoed in the
 * `Location`, and its custom hydrator fills every non-nullable column (so the
 * generic create path persists cleanly). Updates/deletes ride `tracks` — a managed
 * Doctrine entity the hydrator mutates in place. A whole-resource write that embeds
 * relationships (the relationship-strip + single-flush flow) is exercised in
 * {@see RelationshipMutationTest}.
 *
 * The suite drives every request through the shipped
 * {@see \haddowg\JsonApiBundle\Testing\JsonApiBrowser} — `assertCreated()`
 * (201 + `Location` + content type), `assertNoContent()` (204 + empty body) and the
 * fluent document assertions — and proves write-then-read in one test (the browser
 * disables kernel reboot, so the seed and the new row both survive).
 */
#[Group('spec:crud')]
final class WriteTest extends MusicCatalogKernelTestCase
{
    private const string PLAYLIST_ID = '00000000-0000-4000-8000-00000000aaaa';

    #[Test]
    #[Group('spec:creating-resources')]
    public function creatingAResourceReturns201WithLocationAndPersists(): void
    {
        $browser = $this->browser();

        $browser->post('/playlists', [
            'data' => [
                'type' => 'playlists',
                'id' => self::PLAYLIST_ID,
                'attributes' => [
                    'title' => 'Deep Focus',
                    'public' => false,
                ],
            ],
        ])
            ->assertCreated()
            ->assertHeader('Location', 'https://music.example/playlists/' . self::PLAYLIST_ID);

        $browser->getDocument()
            ->assertHasType('playlists')
            ->assertHasId(self::PLAYLIST_ID)
            ->assertHasAttribute('title', 'Deep Focus')
            ->assertHasAttribute('public', false)
            // The custom hydrator derives the read-only slug from the title.
            ->assertHasAttribute('slug', 'deep-focus');

        // The created resource is persisted: a follow-up read on the same browser
        // (reboot disabled) returns it.
        $browser->get('/playlists/' . self::PLAYLIST_ID)
            ->assertFetchedOne()
            ->assertHasAttribute('title', 'Deep Focus');
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function aMutationResponseHonoursInclude(): void
    {
        // Per the spec, a write that returns the resource honours ?include: the
        // create/update arms render the same DataResponse as a fetch (and the
        // Doctrine provider batch-preloads it, ADR 0035). PATCH track 1 — whose
        // to-one `album` is on the same server — asking for ?include=album.
        $this->browser()
            ->patch('/tracks/1?include=album', [
                'data' => [
                    'type' => 'tracks',
                    'id' => '1',
                    'attributes' => ['title' => 'Airbag (Remaster)'],
                ],
            ])
            ->assertFetchedOne()
            ->assertHasIncluded('albums');
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function updatingAResourceReturns200AndAppliesOnlyTheSuppliedAttributes(): void
    {
        // Track 1 is "Airbag", trackNumber 1. A PATCH of the title only must leave
        // the unsupplied attributes untouched.
        $browser = $this->browser();
        $browser->patch('/tracks/1', [
            'data' => [
                'type' => 'tracks',
                'id' => '1',
                'attributes' => ['title' => 'Airbag (Remaster)'],
            ],
        ])
            ->assertFetchedOne()
            ->assertHasAttribute('title', 'Airbag (Remaster)')
            ->assertHasAttribute('trackNumber', 1)
            ->assertHasAttribute('durationSeconds', 284);

        // The change is persisted.
        $browser->get('/tracks/1')
            ->assertFetchedOne()
            ->assertHasAttribute('title', 'Airbag (Remaster)')
            ->assertHasAttribute('durationSeconds', 284);
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function aStoredAsColumnRenameRoundTripsOnUpdate(): void
    {
        // `durationSeconds` is stored on the `length_seconds` column via storedAs();
        // a write through the JSON:API member round-trips transparently.
        $browser = $this->browser();
        $browser->patch('/tracks/1', [
            'data' => [
                'type' => 'tracks',
                'id' => '1',
                'attributes' => ['durationSeconds' => 300],
            ],
        ])
            ->assertFetchedOne()
            ->assertHasAttribute('durationSeconds', 300);

        $browser->get('/tracks/1')->assertFetchedOne()->assertHasAttribute('durationSeconds', 300);
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function anUndeclaredAttributeInAWriteBodyIsSilentlyIgnored(): void
    {
        // Allow-list hydration: an attribute the resource never declared is dropped,
        // never written, and never surfaces on the rendered resource.
        $browser = $this->browser();
        $browser->patch('/tracks/1', [
            'data' => [
                'type' => 'tracks',
                'id' => '1',
                'attributes' => [
                    'title' => 'Edited via allow-list',
                    'isAdmin' => true,
                    'undeclared' => 'nope',
                ],
            ],
        ])
            ->assertFetchedOne()
            ->assertHasAttribute('title', 'Edited via allow-list');

        $data = $this->decode($browser->getResponse())['data'] ?? null;
        self::assertIsArray($data);
        $attributes = $this->attributesOf($data);
        self::assertArrayNotHasKey('isAdmin', $attributes);
        self::assertArrayNotHasKey('undeclared', $attributes);
    }

    #[Test]
    #[Group('spec:deleting-resources')]
    public function deletingAResourceReturns204AndThenItIsGone(): void
    {
        $browser = $this->browser();
        $browser->delete('/tracks/4')->assertNoContent();
        $browser->get('/tracks/4')->getDocument()->assertStatus(404);
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function updatingAMissingResourceReturns404(): void
    {
        $this->browser()
            ->patch('/tracks/999', [
                'data' => [
                    'type' => 'tracks',
                    'id' => '999',
                    'attributes' => ['title' => 'Does not matter'],
                ],
            ])
            ->getErrors()
            ->assertStatus(404);
    }

    #[Test]
    #[Group('spec:deleting-resources')]
    public function deletingAMissingResourceReturns404(): void
    {
        $this->browser()->delete('/tracks/999')->getErrors()->assertStatus(404);
    }

    /**
     * @param array<string, mixed> $resource
     *
     * @return array<string, mixed>
     */
    private function attributesOf(array $resource): array
    {
        $attributes = $resource['attributes'] ?? null;
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }
}
