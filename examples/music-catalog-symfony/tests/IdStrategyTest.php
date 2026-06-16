<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The id-strategy matrix end to end (backs the README id-strategy table; bundle ADR
 * 0039). Each example type keys its entity by a different id source/policy, and this
 * suite exercises the ones the rest of the example suite does not already cover as a
 * side effect:
 *
 *  - **store-provided** (the norm — `artists`/`albums`/`tracks`/`users`/`favorites`/
 *    `libraries`): a create sets nothing on the id and the database assigns the
 *    auto-increment integer, read back on the `201`;
 *  - **client-supplied natural key** (`genres`, `requireClientId()`): a create MUST
 *    carry `data.id` — absent is a `403`, a well-formed slug is used verbatim, a
 *    malformed one is a `422` against the `pattern()` format;
 *  - **app-generated ULID** (`devices`, `ulid()->generated()`): core mints a
 *    Crockford-base32 ULID when a create omits the id.
 *
 * The UUID (`playlists`) and encoded store-provided (`products`) strategies are
 * witnessed in {@see GettingStartedTest}/{@see WriteTest} and {@see EncodedIdTest}.
 */
#[Group('spec:crud')]
final class IdStrategyTest extends MusicCatalogKernelTestCase
{
    // --- store-provided (the example's norm) ---------------------------------

    #[Test]
    #[Group('spec:creating-resources')]
    public function aStoreProvidedCreateLetsTheDatabaseAssignAnIncrementingId(): void
    {
        // `favorites` keys on a store-provided auto-increment integer; the seed holds
        // three, so the DB assigns the next (4) — the create carries no id.
        $response = $this->handle('/favorites', 'POST', [
            'data' => [
                'type' => 'favorites',
                'attributes' => ['favoritedAt' => '2024-07-01T00:00:00+00:00'],
                'relationships' => ['user' => ['data' => ['type' => 'users', 'id' => '1']]],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('4', $this->idOf($response), 'the DB assigned the next auto-increment id');
        self::assertSame('https://music.example/favorites/4', $response->headers->get('Location'));

        // The assigned id round-trips on a follow-up read.
        self::assertSame(200, $this->handle('/favorites/4')->getStatusCode());
    }

    // --- client-supplied natural key (requireClientId) -----------------------

    #[Test]
    #[Group('spec:creating-resources')]
    public function aRequireClientIdCreateUsesTheSuppliedNaturalKey(): void
    {
        // `genres` declares requireClientId(): the supplied slug is the primary key.
        $response = $this->handle('/genres', 'POST', [
            'data' => ['type' => 'genres', 'id' => 'trip-hop', 'attributes' => ['name' => 'Trip-Hop']],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('trip-hop', $this->idOf($response));
        self::assertSame('https://music.example/genres/trip-hop', $response->headers->get('Location'));

        self::assertSame('Trip-Hop', $this->attributesOf($this->handle('/genres/trip-hop'))['name'] ?? null);
    }

    #[Test]
    #[Group('spec:creating-resources')]
    #[Group('spec:errors')]
    public function aRequireClientIdCreateWithoutAnIdIs403(): void
    {
        // requireClientId() makes the id mandatory: absent is ClientGeneratedIdRequired.
        $response = $this->handle('/genres', 'POST', [
            'data' => ['type' => 'genres', 'attributes' => ['name' => 'Ambient']],
        ]);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:creating-resources')]
    #[Group('spec:errors')]
    public function aMalformedNaturalKeyViolatesTheFormatWith422(): void
    {
        // The pattern() format constrains the natural key to a lowercase slug; a value
        // with spaces/capitals fails the bundle's Symfony Validator bridge at /data/id.
        $response = $this->handle('/genres', 'POST', [
            'data' => ['type' => 'genres', 'id' => 'Not A Slug', 'attributes' => ['name' => 'Bad']],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('/data/id', $this->firstErrorPointer($response));
    }

    // --- app-generated ULID --------------------------------------------------

    #[Test]
    #[Group('spec:creating-resources')]
    public function aGeneratedUlidCreateMintsALexicographicallySortableId(): void
    {
        // `devices` declares ulid()->generated(): a create with no id has core mint a
        // 26-char Crockford-base32 ULID.
        $response = $this->handle('/devices', 'POST', [
            'data' => ['type' => 'devices', 'attributes' => ['label' => 'Living Room Speaker']],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $id = $this->idOf($response);
        self::assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $id);
        self::assertSame('https://music.example/devices/' . $id, $response->headers->get('Location'));

        // The minted id round-trips on a follow-up read.
        self::assertSame(
            'Living Room Speaker',
            $this->attributesOf($this->handle('/devices/' . $id))['label'] ?? null,
        );
    }

    // --- helpers -------------------------------------------------------------

    private function idOf(Response $response): string
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $id = $data['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesOf(Response $response): array
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }

    private function firstErrorPointer(Response $response): ?string
    {
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        $first = $errors[0] ?? null;
        self::assertIsArray($first);
        $source = $first['source'] ?? null;
        self::assertIsArray($source);
        $pointer = $source['pointer'] ?? null;

        return \is_string($pointer) ? $pointer : null;
    }
}
