<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The canonical end-to-end onboarding walkthrough, Symfony edition (backs
 * `getting-started.md`): the three worked outcomes of a first music-catalog
 * endpoint over the bundle's reference Doctrine data layer — a `200` collection,
 * a `201` create with a `Location` header, and a route-scoped JSON:API `404` — all
 * rendered as spec-compliant documents with no controller, handler, or serializer
 * written by hand.
 *
 * The `200`/`404` ride the `albums` resource (the simplest fetchable type); the
 * `201` rides `playlists` (the cleanly creatable write witness — its custom
 * hydrator fills every non-nullable column, so the generic create path persists
 * without tripping a constructor-less default).
 */
#[Group('spec:getting-started')]
final class GettingStartedTest extends MusicCatalogKernelTestCase
{
    #[Test]
    #[Group('spec:fetching')]
    public function fetchingTheCollectionReturnsA200JsonApiDocument(): void
    {
        $response = $this->handle('/albums');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/vnd.api+json', (string) $response->headers->get('Content-Type'));

        $document = $this->decode($response);

        self::assertSame(['version' => '1.1'], $document['jsonapi'] ?? null);

        $data = $document['data'] ?? null;
        self::assertIsList($data);
        self::assertNotEmpty($data);

        $first = $data[0];
        self::assertIsArray($first);
        self::assertSame('albums', $first['type'] ?? null);
        self::assertArrayHasKey('id', $first);

        $attributes = $first['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertArrayHasKey('title', $attributes);
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function creatingAResourceReturns201WithLocationAndTheCreatedDocument(): void
    {
        // playlists carry a client-generated UUID id; the custom hydrator derives the
        // read-only slug from the title. The bundle renders 201 + a Location built
        // from the resource's type and the (echoed) id.
        $response = $this->handle('/playlists', 'POST', [
            'data' => [
                'type' => 'playlists',
                'id' => '00000000-0000-4000-8000-00000000abcd',
                'attributes' => [
                    'title' => 'Late Night',
                    'public' => true,
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            'https://music.example/playlists/00000000-0000-4000-8000-00000000abcd',
            $response->headers->get('Location'),
        );

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('playlists', $data['type'] ?? null);
        self::assertSame('00000000-0000-4000-8000-00000000abcd', $data['id'] ?? null);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('Late Night', $attributes['title'] ?? null);
        // Derived by the custom hydrator and rendered read-only.
        self::assertSame('late-night', $attributes['slug'] ?? null);

        // The created resource is persisted: a follow-up read returns it.
        $fetched = $this->decode($this->handle('/playlists/00000000-0000-4000-8000-00000000abcd'))['data'] ?? null;
        self::assertIsArray($fetched);
        $fetchedAttributes = $fetched['attributes'] ?? null;
        self::assertIsArray($fetchedAttributes);
        self::assertSame('Late Night', $fetchedAttributes['title'] ?? null);
    }

    #[Test]
    #[Group('spec:errors')]
    public function fetchingAMissingResourceReturnsAJsonApi404(): void
    {
        // The route exists (it is the show route for `albums`), so the request
        // reaches the handler and the provider's null fetch becomes a route-scoped
        // JSON:API 404 document — not a bare Symfony 404.
        $response = $this->handle('/albums/999');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $first = $errors[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('404', $first['status'] ?? null);
        self::assertSame('RESOURCE_NOT_FOUND', $first['code'] ?? null);
    }
}
