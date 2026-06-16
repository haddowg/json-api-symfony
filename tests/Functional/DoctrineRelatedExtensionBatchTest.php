<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\DataProvider\Doctrine\QueryPurpose;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineRelatedExtensionTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PublishedAuthorsExtension;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The witness that a related type's {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface}
 * is applied ON the related entity even on the parent-rooted PAIR-shape batched fetch
 * (the many-to-many `editors` relation), not on the parent query root — the
 * divergence the batched windowed-include path otherwise had from the related-rooted
 * unbatched fetch (bundle ADR 0061).
 *
 * The {@see PublishedAuthorsExtension} scopes every `authors` query to exclude `Ada
 * Lovelace` (author 1). Author 1 is an editor of articles 1 and 2 (the shared,
 * cross-parent membership), so:
 *  - a COLLECTION include (`GET /articles?include=editors`) takes the batched pair
 *    shape — the constraint must drop author 1 from articles 1 and 2's editors while
 *    keeping author 2;
 *  - a SINGLE-parent include (`GET /articles/1?include=editors`) takes the unbatched
 *    related-rooted fetch — the SAME exclusion, proving witness parity.
 *
 * Were the extension applied on the parent root in the pair shape, the
 * `author.name != 'Ada Lovelace'` clause would target the `articles` entity (no such
 * column) — a Doctrine error, or, given a same-named column, the wrong entity scoped.
 */
final class DoctrineRelatedExtensionBatchTest extends JsonApiFunctionalTestCase
{
    use SeedsDoctrineRelationships;

    private const string BASE_URI = 'https://example.test';

    private const string PROFILE_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"';

    protected static function getKernelClass(): string
    {
        return DoctrineRelatedExtensionTestKernel::class;
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-relationships')]
    public function aRelatedExtensionScopesTheBatchedPairShapeOnTheRelatedEntity(): void
    {
        // The batched pair-shape path: editors is a many-to-many to authors, included
        // over a PAGE of parents under the profile (windowed to page 1). The authors
        // extension excludes author 1, so article 1's editors [1,2] -> [2], article 2's
        // [1] -> [] (empty), article 3's [2] -> [2]. If the extension had landed on the
        // parent (articles) root this would have errored or scoped the wrong entity.
        $document = $this->profileDocument('/articles?include=editors&relatedQuery[editors][sort]=name');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $expected = ['1' => ['2'], '2' => [], '3' => ['2'], '4' => [], '5' => []];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            if (!isset($expected[$id])) {
                continue;
            }
            self::assertSame(
                $expected[$id],
                $this->linkageIds($resource, 'editors'),
                \sprintf('article "%s" editors are scoped to the published authors on the batched pair shape', $id),
            );
        }

        // Author 1 (Ada Lovelace) is excluded from every parent's editors, so the
        // collection include never materializes the excluded author.
        self::assertNotContains('1', $this->includedIds($document, 'authors'));
        self::assertContains('2', $this->includedIds($document, 'authors'));

        // The related-type extension actually ran on the windowed collection include,
        // and reports the related purpose (not the primary FetchCollection) — the
        // distinction a request-aware scope branches on (bundle ADR 0070).
        self::assertContains(QueryPurpose::FetchRelatedCollection, $this->extension()->applied);
        self::assertNotContains(QueryPurpose::FetchCollection, $this->extension()->applied);
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-relationships')]
    public function theUnbatchedSingleParentFetchScopesTheSameRelatedEntity(): void
    {
        // The unbatched related-rooted fetch (a single parent): the SAME exclusion as
        // the batched pair shape — article 1's editors [1,2] -> [2] — proving witness
        // parity between the batched and unbatched related-collection paths.
        $document = $this->profileDocument('/articles/1?include=editors&relatedQuery[editors][sort]=name');

        self::assertSame(['2'], $this->linkageIds($document, 'editors'));
        self::assertSame(['2'], $this->includedIds($document, 'authors'));
    }

    // --- request helpers -------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function profileDocument(string $path): array
    {
        $response = $this->handle(self::BASE_URI . $path, extraServer: ['HTTP_ACCEPT' => self::PROFILE_ACCEPT]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * The linkage `data` ids of a resource's named relationship, in document order.
     * Accepts either a whole document (reads `data`) or a single resource object.
     *
     * @param array<string, mixed> $resourceOrDocument
     *
     * @return list<string>
     */
    private function linkageIds(array $resourceOrDocument, string $relationship): array
    {
        $resource = $resourceOrDocument['data'] ?? $resourceOrDocument;
        self::assertIsArray($resource);
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);

        $relationshipObject = $relationships[$relationship] ?? null;
        self::assertIsArray($relationshipObject, \sprintf('relationship "%s" is present', $relationship));

        $linkage = $relationshipObject['data'] ?? null;
        self::assertIsArray($linkage, \sprintf('relationship "%s" carries linkage data', $relationship));

        $ids = [];
        foreach ($linkage as $identifier) {
            self::assertIsArray($identifier);
            $id = $identifier['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * The ids of the document's `included` resources of `$type`, in document order.
     *
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function includedIds(array $document, string $type): array
    {
        $included = $document['included'] ?? [];
        self::assertIsArray($included);

        $ids = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            if (($resource['type'] ?? null) !== $type) {
                continue;
            }
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    private function extension(): PublishedAuthorsExtension
    {
        $extension = static::getContainer()->get(PublishedAuthorsExtension::class);
        \assert($extension instanceof PublishedAuthorsExtension);

        return $extension;
    }
}
