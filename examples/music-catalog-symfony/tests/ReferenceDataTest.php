<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Routing\RouterInterface;

/**
 * The reference-data witness (backs `custom-data-providers.md` /
 * `capability-composition.md`): a `countries` type with **no Doctrine entity, no
 * Resource, no hydrator** — the simplest custom-provider + standalone-serializer
 * pairing. The {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Provider\CountryProvider}
 * sources its rows from `symfony/intl`'s {@see Countries} (id = ISO 3166-1 alpha-2
 * code, attribute = localized name) and the standalone
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Serializer\CountrySerializer}
 * (registered by `#[AsJsonApiSerializer]`) renders them.
 *
 * It proves an external/static source is a first-class JSON:API collection: it
 * serves `fetchOne`, and **filter / sort / pagination over the non-DB list** by
 * reusing the shared {@see \haddowg\JsonApiBundle\DataProvider\CriteriaApplier} and
 * an {@see \haddowg\JsonApi\Pagination\OffsetWindow}. It is read-only — only the
 * two GET operations are exposed, so writes are unrouted (`405`).
 */
#[Group('spec:fetching')]
final class ReferenceDataTest extends MusicCatalogKernelTestCase
{
    #[Test]
    public function aSingleCountryResolvesByIsoCodeFromTheIntlDataset(): void
    {
        $data = $this->fetch('/countries/GB');
        self::assertSame('countries', $data['type'] ?? null);
        self::assertSame('GB', $data['id'] ?? null);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame(Countries::getName('GB', 'en'), $attributes['name'] ?? null);
    }

    #[Test]
    #[Group('spec:errors')]
    public function anUnknownCountryCodeIsNotFound(): void
    {
        self::assertSame(404, $this->handle('/countries/ZZ')->getStatusCode());
    }

    #[Test]
    public function theFullCollectionRendersEveryCountryFromTheNonDatabaseSource(): void
    {
        $data = $this->decode($this->handle('/countries'))['data'] ?? null;
        self::assertIsList($data);
        // The whole ICU country list, with no database behind it.
        self::assertCount(\count(Countries::getCountryCodes()), $data);
    }

    #[Test]
    #[Group('spec:filtering')]
    public function aSubstringNameFilterRunsOverTheInMemoryListViaCriteriaApplier(): void
    {
        // The provider declares its own `filter[name]` vocabulary and runs it through
        // the shared CriteriaApplier — a case-insensitive substring match.
        $data = $this->decode($this->handle('/countries?filter[name]=United King'))['data'] ?? null;
        self::assertIsList($data);
        self::assertCount(1, $data);

        $first = $data[0];
        self::assertIsArray($first);
        self::assertSame('GB', $first['id'] ?? null);
    }

    #[Test]
    #[Group('spec:sorting')]
    public function sortingRunsOverTheInMemoryListViaCriteriaApplier(): void
    {
        $ascending = $this->ids($this->decode($this->handle('/countries?sort=name')));
        $descending = $this->ids($this->decode($this->handle('/countries?sort=-name')));

        self::assertNotSame([], $ascending);
        // The descending order is the ascending order reversed — the sort really ran.
        self::assertSame(\array_reverse($ascending), $descending);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function paginationSlicesTheInMemoryListWithAnOffsetWindow(): void
    {
        // The provider executes an OffsetWindow over the sorted list: two disjoint,
        // ordered pages of size 2.
        $pageOne = $this->ids($this->decode($this->handle('/countries?sort=name&page[number]=1&page[size]=2')));
        $pageTwo = $this->ids($this->decode($this->handle('/countries?sort=name&page[number]=2&page[size]=2')));

        self::assertCount(2, $pageOne);
        self::assertCount(2, $pageTwo);
        self::assertSame([], \array_intersect($pageOne, $pageTwo));
    }

    #[Test]
    #[Group('spec:filtering')]
    public function anUndeclaredFilterIsRejected(): void
    {
        // The vocabulary is the provider's own (only `name`); anything else 400s.
        $response = $this->handle('/countries?filter[nope]=x');
        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function theTypeIsReadOnlySoOnlyTheTwoFetchRoutesExist(): void
    {
        // Only FetchCollection + FetchOne are exposed, so no write route is emitted.
        // Asserted against the route collection (not by probing an unrouted verb,
        // which would log a framework exception PHPUnit flags as risky).
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $names = $router->getRouteCollection()->all();

        self::assertArrayHasKey('jsonapi.countries.index', $names);
        self::assertArrayHasKey('jsonapi.countries.show', $names);
        self::assertArrayNotHasKey('jsonapi.countries.create', $names);
        self::assertArrayNotHasKey('jsonapi.countries.update', $names);
        self::assertArrayNotHasKey('jsonapi.countries.delete', $names);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
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
