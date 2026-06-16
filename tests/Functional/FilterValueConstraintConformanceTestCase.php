<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The filter-value-constraint acceptance suite (bundle ADR 0048): filters that
 * declare **value constraints** — `id` (`WhereIdIn->integer()`), `numericId`
 * (`Where->integer()`), `byCategory` (`Where->pattern(...)`) on the `articles`
 * resource, and a relation-scoped `commentId` (`Where->integer()`) on the
 * `comments` relation — asserted end-to-end on the primary collection AND the
 * related-collection endpoint.
 *
 * Abstract over the kernel so the **same assertions** run against the in-memory
 * provider ({@see InMemoryFilterValueConstraintTest}) and the Doctrine provider
 * ({@see DoctrineFilterValueConstraintTest}). The validation is **pre-provider**
 * (on the value, before the filter reaches the data layer), so a mistyped value
 * is a deliberate `400` with `source.parameter` on **both** providers — the bad
 * value never reaches the query — rather than the provider's silent non-match
 * (which is what an unvalidated mistyped value yields on both the in-memory
 * provider and this suite's loosely-typed sqlite Doctrine kernel; on a strict
 * driver such as Postgres it would instead be a PDO `500`). The `400` is what is
 * asserted below; the avoided-`500` claim is strict-driver-specific.
 *
 * With the canonical fixtures the `articles` ids are `1`-`5` and each article's
 * `comments` are wired by {@see \haddowg\JsonApiBundle\Tests\Functional\App\ArticleFixtures}.
 */
abstract class FilterValueConstraintConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aMistypedConstrainedFilterValueIsACleanBadRequest(): void
    {
        // filter[id] is constrained ->integer(); "banana" is not — so it is a clean
        // 400 BEFORE any query runs. The bad value never reaches the provider, so a
        // strict driver (Postgres) cannot raise a PDO 500 (the sqlite kernel here
        // would otherwise silently non-match); either way the contract is the 400.
        $response = $this->handle('/articles?filter[id]=banana');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $error = $this->firstError($this->decode($response));
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('FILTER_VALUE_INVALID', $error['code'] ?? null);
        self::assertSame(['parameter' => 'filter[id]'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aMistypedMemberOfAConstrainedSetIsACleanBadRequest(): void
    {
        // Each member of an IN-style set is validated individually: 1 and 3 are
        // integers, "banana" is not — so the whole request is a 400.
        $response = $this->handle('/articles?filter[id]=1,banana,3');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());

        $error = $this->firstError($this->decode($response));
        self::assertSame('FILTER_VALUE_INVALID', $error['code'] ?? null);
        self::assertSame(['parameter' => 'filter[id]'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aValidConstrainedFilterValueStillFiltersCorrectly(): void
    {
        // The valid integer members pass validation and filter as before.
        $document = $this->fetchDocument('/articles?filter[id]=1,4&sort=title');

        self::assertSame(['1', '4'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aMistypedSingleScalarConstrainedFilterValueIsACleanBadRequest(): void
    {
        // numericId is a single-value Where->integer(): the single-scalar path.
        $response = $this->handle('/articles?filter[numericId]=banana');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());

        $error = $this->firstError($this->decode($response));
        self::assertSame('FILTER_VALUE_INVALID', $error['code'] ?? null);
        self::assertSame(['parameter' => 'filter[numericId]'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aValidSingleScalarConstrainedFilterValueStillFiltersCorrectly(): void
    {
        // A valid integer passes and selects the one matching row.
        $document = $this->fetchDocument('/articles?filter[numericId]=3');

        self::assertSame(['3'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aPatternConstrainedFilterRejectsAnUnmatchedValue(): void
    {
        // byCategory is constrained ->pattern(guide|news|opinion): "nope" fails.
        $response = $this->handle('/articles?filter[byCategory]=nope');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());

        $error = $this->firstError($this->decode($response));
        self::assertSame('FILTER_VALUE_INVALID', $error['code'] ?? null);
        self::assertSame(['parameter' => 'filter[byCategory]'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function anUnconstrainedFilterIsUnaffected(): void
    {
        // titleContains carries no constraints: any value passes, exactly as today.
        // Only "Second article" contains "article".
        $document = $this->fetchDocument('/articles?filter[titleContains]=article');

        self::assertSame(['2'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function anAuthorSetDefaultFilterValueIsNotValidated(): void
    {
        // The `anyTitle` filter is constrained ->pattern('^.+$') but defaults to ''
        // — a value that would VIOLATE that pattern. No filter[...] keys are
        // requested, so only the server-set default folds in; the default is trusted
        // and never validated, so the request is a 200 (not a 400), and the
        // all-matching `title LIKE '%%'` default leaves every row in place.
        $document = $this->fetchDocument('/articles?sort=title');

        self::assertSame(['5', '3', '1', '2', '4'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function aRelatedCollectionValidatesAConstrainedRelationFilter(): void
    {
        // commentId is a relation-scoped Where->integer() on the comments relation,
        // available only on GET /articles/{id}/comments — a mistyped value is a clean
        // 400 there too, before the related-collection fetch reaches the provider.
        $response = $this->handle('/articles/1/comments?filter[commentId]=banana');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());

        $error = $this->firstError($this->decode($response));
        self::assertSame('FILTER_VALUE_INVALID', $error['code'] ?? null);
        self::assertSame(['parameter' => 'filter[commentId]'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-filtering')]
    public function aRelatedCollectionValidConstrainedRelationFilterStillFilters(): void
    {
        // A valid integer passes; article 1 is wired to comment 1, so the
        // relation-scoped id filter selects it.
        $document = $this->fetchDocument('/articles/1/comments?filter[commentId]=1');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            self::assertSame('comments', $resource['type'] ?? null);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        self::assertSame(['1'], $ids);
    }

    /**
     * Fetches `$path` and returns the decoded document, asserting a 200 JSON:API
     * response.
     *
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
     * The ids of the document's primary `articles` data, in document order.
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
            self::assertSame('articles', $resource['type'] ?? null);

            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * The document's first error object.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function firstError(array $document): array
    {
        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $first = $errors[0] ?? null;
        self::assertIsArray($first);

        /** @var array<string, mixed> $first */
        return $first;
    }
}
