<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

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
 */
#[Group('spec:crud')]
final class WriteTest extends MusicCatalogKernelTestCase
{
    private const string PLAYLIST_ID = '00000000-0000-4000-8000-00000000aaaa';

    #[Test]
    #[Group('spec:creating-resources')]
    public function creatingAResourceReturns201WithLocationAndPersists(): void
    {
        $response = $this->handle('/playlists', 'POST', [
            'data' => [
                'type' => 'playlists',
                'id' => self::PLAYLIST_ID,
                'attributes' => [
                    'title' => 'Deep Focus',
                    'public' => false,
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->dataOf($response);
        self::assertSame('playlists', $data['type'] ?? null);
        self::assertSame(self::PLAYLIST_ID, $data['id'] ?? null);
        self::assertSame(
            'https://music.example/playlists/' . self::PLAYLIST_ID,
            $response->headers->get('Location'),
        );

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('Deep Focus', $attributes['title'] ?? null);
        self::assertFalse($attributes['public'] ?? null);
        // The custom hydrator derives the read-only slug from the title.
        self::assertSame('deep-focus', $attributes['slug'] ?? null);

        // The created resource is persisted: a follow-up read returns it.
        $fetched = $this->attributesOf($this->handle('/playlists/' . self::PLAYLIST_ID));
        self::assertSame('Deep Focus', $fetched['title'] ?? null);
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function updatingAResourceReturns200AndAppliesOnlyTheSuppliedAttributes(): void
    {
        // Track 1 is "Airbag", trackNumber 1. A PATCH of the title only must leave
        // the unsupplied attributes untouched.
        $response = $this->handle('/tracks/1', 'PATCH', [
            'data' => [
                'type' => 'tracks',
                'id' => '1',
                'attributes' => ['title' => 'Airbag (Remaster)'],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $attributes = $this->attributesOf($response);
        self::assertSame('Airbag (Remaster)', $attributes['title'] ?? null);
        self::assertSame(1, $attributes['trackNumber'] ?? null);
        self::assertSame(284, $attributes['durationSeconds'] ?? null);

        // The change is persisted.
        $fetched = $this->attributesOf($this->handle('/tracks/1'));
        self::assertSame('Airbag (Remaster)', $fetched['title'] ?? null);
        self::assertSame(284, $fetched['durationSeconds'] ?? null);
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function aStoredAsColumnRenameRoundTripsOnUpdate(): void
    {
        // `durationSeconds` is stored on the `length_seconds` column via storedAs();
        // a write through the JSON:API member round-trips transparently.
        $response = $this->handle('/tracks/1', 'PATCH', [
            'data' => [
                'type' => 'tracks',
                'id' => '1',
                'attributes' => ['durationSeconds' => 300],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(300, $this->attributesOf($response)['durationSeconds'] ?? null);
        self::assertSame(300, $this->attributesOf($this->handle('/tracks/1'))['durationSeconds'] ?? null);
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function anUndeclaredAttributeInAWriteBodyIsSilentlyIgnored(): void
    {
        // Allow-list hydration: an attribute the resource never declared is dropped,
        // never written, and never surfaces on the rendered resource.
        $response = $this->handle('/tracks/1', 'PATCH', [
            'data' => [
                'type' => 'tracks',
                'id' => '1',
                'attributes' => [
                    'title' => 'Edited via allow-list',
                    'isAdmin' => true,
                    'undeclared' => 'nope',
                ],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $attributes = $this->attributesOf($response);
        self::assertSame('Edited via allow-list', $attributes['title'] ?? null);
        self::assertArrayNotHasKey('isAdmin', $attributes);
        self::assertArrayNotHasKey('undeclared', $attributes);
    }

    #[Test]
    #[Group('spec:deleting-resources')]
    public function deletingAResourceReturns204AndThenItIsGone(): void
    {
        $response = $this->handle('/tracks/4', 'DELETE');

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getContent());

        self::assertSame(404, $this->handle('/tracks/4')->getStatusCode());
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function updatingAMissingResourceReturns404(): void
    {
        $response = $this->handle('/tracks/999', 'PATCH', [
            'data' => [
                'type' => 'tracks',
                'id' => '999',
                'attributes' => ['title' => 'Does not matter'],
            ],
        ]);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    #[Group('spec:deleting-resources')]
    public function deletingAMissingResourceReturns404(): void
    {
        self::assertSame(404, $this->handle('/tracks/999', 'DELETE')->getStatusCode());
    }

    /**
     * @return array<string, mixed>
     */
    private function dataOf(Response $response): array
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesOf(Response $response): array
    {
        $attributes = $this->dataOf($response)['attributes'] ?? null;
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }
}
