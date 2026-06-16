<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\DataProvider\Doctrine\QueryPurpose;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\RequestAwareAuthorsExtension;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\RequestAwareExtensionTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The witness for the request-aware {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext}
 * (bundle ADR 0070): a {@see RequestAwareAuthorsExtension} scopes `authors`,
 * branching on the context's parsed JSON:API request.
 *
 *  - On the primary `GET /authors` collection the request is `null` and the purpose
 *    is {@see QueryPurpose::FetchCollection}, so the request-aware branch never fires
 *    (the unconditional base scope still excludes author 1).
 *  - On a related `GET /articles/{id}/editors` load the request is present and the
 *    purpose is {@see QueryPurpose::FetchRelatedCollection}, so a gating header read
 *    off the request applies a second exclusion.
 *
 * The two are otherwise the same `authors` type — only the request-awareness of the
 * context distinguishes them, which is the whole point of the slice.
 */
final class RequestAwareExtensionTest extends JsonApiFunctionalTestCase
{
    use SeedsDoctrineRelationships;

    private const string BASE_URI = 'https://example.test';

    protected static function getKernelClass(): string
    {
        return RequestAwareExtensionTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelatedLoadCarriesTheRequestAndReportsTheRelatedPurpose(): void
    {
        // Article 1's editors are authors [1, 2]. The unconditional base scope drops
        // author 1 (Ada Lovelace) on every load, so without the gating header the
        // related endpoint renders editor [2] (Grace Hopper).
        $document = $this->decode($this->handle(self::BASE_URI . '/articles/1/editors'));

        self::assertSame(['2'], $this->dataIds($document));

        // The extension ran on the related load WITH the request, reporting the
        // related purpose — never the primary FetchCollection.
        $seen = $this->extension()->seen;
        self::assertContains(
            ['purpose' => QueryPurpose::FetchRelatedCollection, 'requestPresent' => true],
            $seen,
        );
        foreach ($seen as $entry) {
            self::assertSame(QueryPurpose::FetchRelatedCollection, $entry['purpose']);
            self::assertTrue($entry['requestPresent']);
        }
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theGatingHeaderReadOffTheRequestNarrowsTheRelatedLoad(): void
    {
        // With the gating header set, the request-aware branch fires on the related
        // load and excludes author 2 (Grace Hopper) too — so article 1's editors,
        // already [2] under the base scope, become empty.
        $document = $this->decode($this->handle(
            self::BASE_URI . '/articles/1/editors',
            extraServer: ['HTTP_' . \str_replace('-', '_', \strtoupper(RequestAwareAuthorsExtension::HIDE_EDITORS_HEADER)) => 'true'],
        ));

        self::assertSame([], $this->dataIds($document));
    }

    #[Test]
    #[Group('spec:fetching-resources')]
    public function thePrimaryCollectionCarriesNoRequestSoTheGatingHeaderIsInert(): void
    {
        // The primary GET /authors collection: the SPI carries no request, so the
        // context's request is null and the purpose is FetchCollection. Even with the
        // gating header set, the request-aware branch cannot fire — author 2 stays
        // visible; only the unconditional base scope (author 1 excluded) applies.
        $document = $this->decode($this->handle(
            self::BASE_URI . '/authors?sort=name',
            extraServer: ['HTTP_' . \str_replace('-', '_', \strtoupper(RequestAwareAuthorsExtension::HIDE_EDITORS_HEADER)) => 'true'],
        ));

        self::assertSame(['2'], $this->dataIds($document));

        $seen = $this->extension()->seen;
        self::assertContains(
            ['purpose' => QueryPurpose::FetchCollection, 'requestPresent' => false],
            $seen,
        );
        foreach ($seen as $entry) {
            self::assertSame(QueryPurpose::FetchCollection, $entry['purpose']);
            self::assertFalse($entry['requestPresent']);
        }
    }

    /**
     * The `data` ids of a document (a collection or a related collection), in order.
     *
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function dataIds(array $document): array
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

    private function extension(): RequestAwareAuthorsExtension
    {
        $extension = static::getContainer()->get(RequestAwareAuthorsExtension::class);
        \assert($extension instanceof RequestAwareAuthorsExtension);

        return $extension;
    }
}
