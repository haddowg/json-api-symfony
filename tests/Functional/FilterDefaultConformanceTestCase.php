<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The filter-default acceptance suite: a `category` filter declared with
 * `->default('guide')` on the `articles` resource, asserted end-to-end on
 * `GET /articles`. Abstract over the kernel so the **same assertions** run
 * against the in-memory provider ({@see InMemoryFilterDefaultTest}) and the
 * Doctrine provider ({@see DoctrineFilterDefaultTest}) — the defaults are
 * folded once in the shared `CriteriaApplier`, so a divergence localizes to a
 * provider's filter *execution*, never its default handling.
 *
 * With the canonical fixtures the default scopes a bare collection to the
 * three guide rows (ids 1, 2, 4); the two news rows (3, 5) appear only when
 * the request overrides the default.
 */
abstract class FilterDefaultConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aDeclaredDefaultNarrowsTheCollectionWhenTheKeyIsAbsent(): void
    {
        // No filter[category] in the request → the declared default applies.
        $document = $this->fetchDocument('/articles?sort=title');

        self::assertSame(['1', '2', '4'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aRequestedValueOverridesTheDefault(): void
    {
        // An explicit value wins over the default, surfacing the news rows the
        // default would otherwise hide.
        $document = $this->fetchDocument('/articles?filter[category]=news&sort=title');

        self::assertSame(['5', '3'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:fetching-pagination')]
    public function aDefaultIsCountedInThePaginationTotal(): void
    {
        // The default narrows before the pre-window COUNT, so the total
        // describes the defaulted (guide-only) collection, not all five rows.
        // The `articles` paginator is count-free by default (G21), so the count
        // is opted into with `?withCount=_self_` under the Countable profile.
        $response = $this->handle('/articles?sort=title&page[number]=1&page[size]=2&withCount=_self_', extraServer: [
            'HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . CountableProfile::URI . '"',
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);

        self::assertSame(['1', '2'], $this->ids($document));

        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertSame(3, $meta['total'] ?? null);

        $page = $this->pageMeta($document);
        self::assertSame(3, $page['total'] ?? null);
        self::assertSame(2, $page['lastPage'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDocument(string $path): array
    {
        $response = $this->handle($path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->decode($response);
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
            self::assertSame('articles', $resource['type'] ?? null);

            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function pageMeta(array $document): array
    {
        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);

        $page = $meta['page'] ?? null;
        self::assertIsArray($page);

        /** @var array<string, mixed> $page */
        return $page;
    }
}
