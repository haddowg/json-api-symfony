<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Declared filter-value validation (core ADR 0055, bundle ADR 0048, backs the
 * "Validating filter values" README note): a value-carrying filter may declare
 * **value constraints**, validated by the bundle BEFORE the filter reaches the
 * provider — so a mistyped value is a clean `400` (`FILTER_VALUE_INVALID`,
 * `source.parameter`) rather than the provider's unhelpful default for a
 * type-mismatched value (a silent non-match here on sqlite, or a PDO `500` on a
 * strict driver such as Postgres).
 *
 * Two witnesses, both over the reference Doctrine provider across the seeded
 * catalog:
 *
 *  - PRIMARY collection: `TrackResource`'s `explicit` filter declares `->boolean()`,
 *    so `GET /tracks?filter[explicit]=banana` is a clean `400` instead of silently
 *    coercing to `false` and mis-matching;
 *  - RELATED collection: `AlbumResource`'s relation-scoped `longerThan` filter on
 *    the integer `length_seconds` column declares `->integer()`, so
 *    `GET /albums/1/tracks?filter[longerThan]=banana` is a clean `400` — the bad
 *    value never reaches the query, so a strict driver cannot `500` (this sqlite
 *    kernel would otherwise silently non-match).
 *
 * Album 1 (OK Computer) holds tracks 1 (Airbag, 284s), 2 (Paranoid Android, 383s,
 * explicit) and 3 (Exit Music, 264s); the `tracks` `explicit` default filter hides
 * track 2, so the related collection's visible members are tracks 1 and 3.
 */
#[Group('spec:fetching-filtering')]
#[Group('spec:errors')]
final class FilterValueConstraintTest extends MusicCatalogKernelTestCase
{
    // --- primary collection --------------------------------------------------

    #[Test]
    public function aMistypedConstrainedPrimaryFilterValueIsACleanBadRequest(): void
    {
        // `explicit` is constrained ->boolean(); "banana" is not a boolean wire form,
        // so it is a clean 400 — NOT a silent coercion to false.
        $error = $this->errorOn('/tracks?filter[explicit]=banana');

        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('FILTER_VALUE_INVALID', $error['code'] ?? null);
        self::assertSame(['parameter' => 'filter[explicit]'], $error['source'] ?? null);
        self::assertNotEmpty($error['detail'] ?? null, 'the violation message renders as detail');
    }

    #[Test]
    public function aValidConstrainedPrimaryFilterValueStillFilters(): void
    {
        // A valid boolean wire form passes validation and filters as before:
        // explicit=true surfaces the one explicit track (Paranoid Android, track 2).
        self::assertSame(['2'], $this->ids($this->fetch('/tracks?filter[explicit]=true')));
    }

    #[Test]
    public function anUnconstrainedPrimaryFilterIsUnaffected(): void
    {
        // `title` carries no value constraints, so any value still passes exactly as
        // before — the `like` substring match on "air" selects only Airbag (track 1).
        self::assertSame(['1'], $this->ids($this->fetch('/tracks?filter[title]=air')));
    }

    // --- related collection --------------------------------------------------

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aMistypedConstrainedRelationFilterValueIsACleanBadRequest(): void
    {
        // `longerThan` is the relation-scoped Where->integer() on the integer
        // `length_seconds` column. "banana" is not an integer, so it is a clean 400
        // BEFORE the related-collection fetch reaches the Doctrine provider — the bad
        // value never reaches the query, so a strict driver cannot 500 (this sqlite
        // kernel would otherwise silently non-match on the integer column).
        $response = $this->handle('/albums/1/tracks?filter[longerThan]=banana');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $error = $this->firstError($response);
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('FILTER_VALUE_INVALID', $error['code'] ?? null);
        self::assertSame(['parameter' => 'filter[longerThan]'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aValidConstrainedRelationFilterValueStillFilters(): void
    {
        // A valid integer passes and filters as before: longerThan=270 keeps track 1
        // (284s) and excludes track 3 (264s); track 2 (383s) is hidden by the related
        // `tracks` resource's `explicit` default filter.
        self::assertSame(['1'], $this->ids($this->fetch('/albums/1/tracks?filter[longerThan]=270')));
    }

    // --- the author-set default ----------------------------------------------

    #[Test]
    public function anAuthorSetDefaultFilterValueIsNotValidated(): void
    {
        // `explicit` is constrained ->boolean() but defaults to the bool `false` when
        // no filter[explicit] key is supplied. Only client-supplied values are
        // validated, so the bare collection folds in the trusted default and renders
        // the three non-explicit tracks — a 200, not a 400.
        self::assertSame(['1', '3', '4'], $this->ids($this->fetch('/tracks?sort=title')));
    }

    // --- helpers -------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $path): array
    {
        $response = $this->handle($path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->decode($response);
    }

    /**
     * Handles `$path`, asserts a 400 JSON:API error response and returns the first
     * error object.
     *
     * @return array<string, mixed>
     */
    private function errorOn(string $path): array
    {
        $response = $this->handle($path);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->firstError($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function firstError(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $first = $errors[0] ?? null;
        self::assertIsArray($first);

        /** @var array<string, mixed> $first */
        return $first;
    }

    /**
     * The ids of the document's primary data, in document order.
     *
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
