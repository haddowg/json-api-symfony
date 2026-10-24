<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which pivot fields a member's `meta.pivot` carries is decided per member: `label`
 * on `playlists.tracks` is `hidden(when:)` for the track titled "Outro" only, so that
 * member renders without it while the others render it. A readable field the join
 * row holds no value for renders as `null` rather than being left out.
 */
final class DoctrinePivotReadableFieldsTest extends JsonApiFunctionalTestCase
{
    use SeedsDoctrinePivot;

    private const string BASE_URI = 'https://example.test';

    /**
     * Playlist 1 holds Intro (1), Outro (2) and Bridge (3).
     *
     * @return iterable<string>
     */
    private static function paths(): iterable
    {
        yield '/playlists/1/relationships/tracks';
        yield '/playlists/1/tracks';
        yield '/playlists/1?include=tracks';
    }

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aHiddenWhenPredicateHidesAPivotFieldForOneMemberOnly(): void
    {
        foreach (self::paths() as $path) {
            $pivots = $this->pivotsById($this->handle(self::BASE_URI . $path));

            self::assertSame([1, 2, 3], \array_keys($pivots), $path);
            self::assertSame('tagged', $pivots['1']['label'] ?? null, $path);
            self::assertSame('tagged', $pivots['3']['label'] ?? null, $path);
            self::assertArrayNotHasKey('label', $pivots['2'], $path);
            // The member's other readable fields still render.
            self::assertSame(2, $pivots['2']['position'] ?? null, $path);
        }
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aReadablePivotFieldWithNoStoredValueRendersNull(): void
    {
        // Bridge joins playlist 2 with only a position: weight and label stay unset.
        $response = $this->handle(
            self::BASE_URI . '/playlists/2/relationships/tracks',
            'POST',
            ['data' => [['type' => 'tracks', 'id' => '3', 'meta' => ['pivot' => ['position' => 5]]]]],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        foreach (['/playlists/2/relationships/tracks', '/playlists/2/tracks', '/playlists/2?include=tracks'] as $path) {
            $pivot = $this->pivotsById($this->handle(self::BASE_URI . $path))['3'] ?? null;
            self::assertIsArray($pivot, $path);

            self::assertSame(5, $pivot['position'] ?? null, $path);
            self::assertArrayHasKey('weight', $pivot, $path);
            self::assertNull($pivot['weight'], $path);
            self::assertArrayHasKey('label', $pivot, $path);
            self::assertNull($pivot['label'], $path);
        }
    }

    /**
     * The `meta.pivot` of each linkage or resource object for `tracks` in the
     * document, keyed by track id (PHP casts the numeric ids to int keys) and sorted by it.
     *
     * @return array<array-key, array<mixed>>
     */
    private function pivotsById(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        if (($data['type'] ?? null) === 'playlists') {
            $relationships = $data['relationships'] ?? null;
            self::assertIsArray($relationships);
            $tracks = $relationships['tracks'] ?? null;
            self::assertIsArray($tracks);
            $data = $tracks['data'] ?? null;
            self::assertIsArray($data);
        }

        $pivots = [];
        foreach ($data as $member) {
            self::assertIsArray($member);
            self::assertSame('tracks', $member['type'] ?? null);
            $id = $member['id'] ?? null;
            self::assertIsString($id);
            $meta = $member['meta'] ?? null;
            self::assertIsArray($meta);
            $pivot = $meta['pivot'] ?? null;
            self::assertIsArray($pivot);
            $pivots[$id] = $pivot;
        }
        \ksort($pivots);

        return $pivots;
    }
}
