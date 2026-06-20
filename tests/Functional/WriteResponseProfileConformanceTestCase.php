<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The write half of the Relationship Queries / Countable profile parity contract,
 * run identically against the in-memory provider
 * ({@see InMemoryWriteResponseProfileTest}) and the Doctrine provider
 * ({@see DoctrineWriteResponseProfileTest}).
 *
 * A write response (`POST /{type}`, `PATCH /{type}/{id}`) is the SAME
 * {@see \haddowg\JsonApi\Response\DataResponse::fromResource()} value object a read
 * renders, so it honours the profile and `?withCount` identically (bundle ADRs
 * 0052, 0053, 0086): a write with `?include` + a `relatedQuery` filter renders the
 * included members FILTERED to page 1 of the filtered set, a write with
 * `?withCount` reports the relationship object's `meta.total`, and a `relatedQuery`
 * on a relation whose data does NOT render this request (a lazy, not-included
 * to-many) is NOT applied — consistent with reads.
 *
 * Both kernels seed the same canonical {@see App\ArticleFixtures} graph: article 1
 * owns comments [1 "First!", 2 "Nice write-up."] (`comments`, a `withData()`
 * to-many, and `pagedComments`, a countable to-many over the same column) and
 * features comments [1, 2] (`lazyComments`, a lazy not-included to-many over a
 * SEPARATE column). A PATCH that touches only an attribute leaves those
 * associations intact, so the rendered write response exercises the windowing /
 * counting seams over the seeded membership.
 */
abstract class WriteResponseProfileConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string BASE_URI = 'https://example.test';

    private const string PROFILE_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"';

    private const string COUNT_PROFILE_ACCEPT = 'application/vnd.api+json;profile="'
        . RelationshipQueriesProfile::URI . ' ' . CountableProfile::URI . '"';

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:crud')]
    public function aPatchResponseHonoursTheRelatedQueryFilterOnAnIncludedRelationship(): void
    {
        // PATCH only the title; the response includes `comments` (article 1 owns
        // [1, 2]) with a relatedQuery filter narrowing the included set to comment 1
        // ("First!"). The write response is the same DataResponse as a read, so the
        // window fires and the included members are FILTERED to page 1 — exactly as
        // the read arm would render the same URL.
        $document = $this->profileWrite(
            '/articles/1?include=comments&relatedQuery[comments][filter][commentBody]=First',
            'PATCH',
            $this->patchTitle('Edited under the profile'),
        );

        self::assertSame('Edited under the profile', $this->title($document));
        self::assertSame(['1'], $this->linkageIds($document, 'comments'));
        self::assertSame(['1'], $this->includedIds($document, 'comments'));
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:crud')]
    public function aPostResponseHonoursTheRelatedQueryFilterOnAnIncludedRelationship(): void
    {
        // POST a new article carrying a `comments` association of [1, 2] in the body,
        // with the response including `comments` narrowed by a relatedQuery filter to
        // comment 1 ("First!"). The 201 write response honours the profile exactly as
        // the read does.
        $response = $this->handle(
            self::BASE_URI . '/articles?include=comments&relatedQuery[comments][filter][commentBody]=First',
            'POST',
            [
                'data' => [
                    'type' => 'articles',
                    'attributes' => ['title' => 'Created under the profile', 'category' => 'news'],
                    'relationships' => [
                        'comments' => ['data' => [
                            ['type' => 'comments', 'id' => '1'],
                            ['type' => 'comments', 'id' => '2'],
                        ]],
                    ],
                ],
            ],
            ['HTTP_ACCEPT' => self::PROFILE_ACCEPT],
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);

        self::assertSame(['1'], $this->linkageIds($document, 'comments'), 'the 201 linkage is the filtered page');
        self::assertSame(['1'], $this->includedIds($document, 'comments'), 'the 201 included members are filtered');
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:crud')]
    public function aPatchResponseReportsAWithCountOnTheRelationshipObject(): void
    {
        // `pagedComments` is a countable to-many over the same `comments` column
        // (article 1 owns [1, 2]). A PATCH with `?withCount=pagedComments` under the
        // Countable profile installs the batched count, so the relationship object on
        // the write response carries `meta.total = 2` — the same count a read renders.
        $response = $this->handle(
            self::BASE_URI . '/articles/1?withCount=pagedComments',
            'PATCH',
            $this->patchTitle('Counted under the profile'),
            ['HTTP_ACCEPT' => self::COUNT_PROFILE_ACCEPT],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);

        $meta = $this->relationshipObject($document['data'] ?? null, 'pagedComments')['meta'] ?? null;
        self::assertIsArray($meta, 'a counted relationship object carries meta');
        self::assertSame(2, $meta['total'] ?? null, 'the write response reports the relationship count');
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:crud')]
    public function aRelatedQueryFilterOnANotRenderedRelationshipDoesNotApplyToTheWriteResponse(): void
    {
        // `lazyComments` is a lazy, links-only-by-default to-many over the SEPARATE
        // `featuredComments` column (article 1 features [1, 2]). It is NOT included and
        // NOT `withData()`, so its data does not render this PATCH — the window must NOT
        // fire (bundle ADR 0086), consistent with reads. A relatedQuery filter that
        // would narrow it to comment 1 alone has NO effect: the relationship is never
        // the filtered singleton, and no window pagination link mirroring the filter
        // appears.
        $document = $this->profileWrite(
            '/articles/1?relatedQuery[lazyComments][filter][body]=' . \rawurlencode('First!'),
            'PATCH',
            $this->patchTitle('Lazy bystander under the profile'),
        );

        $relationshipObject = $this->relationshipObject($document['data'] ?? null, 'lazyComments');

        // The window never fired: no relatedQuery-mirroring pagination link.
        self::assertWindowDidNotFire($relationshipObject, 'body=First');

        // The linkage is never the filtered singleton: either links-only (no data member
        // at all, the Doctrine lazy default) or the full unfiltered membership (the
        // in-memory witness) — but NOT [1] alone.
        if (\array_key_exists('data', $relationshipObject)) {
            self::assertNotSame(
                ['1'],
                $this->linkageIds($document, 'lazyComments'),
                'a not-rendered lazy to-many must not be windowed on a write response (no leak)',
            );
        }
    }

    // --- request helpers -------------------------------------------------------

    /**
     * A profile-negotiated write (PATCH/POST): the `Accept` carries the Relationship
     * Queries profile URI, so the relatedQuery/rQ family parses on the write response.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function profileWrite(string $path, string $method, array $body): array
    {
        $response = $this->handle(self::BASE_URI . $path, $method, $body, ['HTTP_ACCEPT' => self::PROFILE_ACCEPT]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * A minimal PATCH body that edits only the article's title — enough to exercise
     * the write response render while leaving the seeded associations intact.
     *
     * @return array<string, mixed>
     */
    private function patchTitle(string $title): array
    {
        return [
            'data' => [
                'type' => 'articles',
                'id' => '1',
                'attributes' => ['title' => $title],
            ],
        ];
    }

    // --- assertion helpers -----------------------------------------------------

    /**
     * @param array<string, mixed> $document
     */
    private function title(array $document): string
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        $title = $attributes['title'] ?? null;
        self::assertIsString($title);

        return $title;
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
        $relationshipObject = $this->relationshipObject($resource, $relationship);

        $data = $relationshipObject['data'] ?? null;
        self::assertIsArray($data, \sprintf('relationship "%s" carries linkage data', $relationship));

        $ids = [];
        foreach ($data as $identifier) {
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

    /**
     * The whole relationship object of a resource's named relationship (links + any
     * data/meta).
     *
     * @return array<string, mixed>
     */
    private function relationshipObject(mixed $resource, string $relationship): array
    {
        self::assertIsArray($resource);
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);

        $relationshipObject = $relationships[$relationship] ?? null;
        self::assertIsArray($relationshipObject, \sprintf('relationship "%s" is present', $relationship));

        /** @var array<string, mixed> $relationshipObject */
        return $relationshipObject;
    }

    /**
     * Asserts a relationship object was NOT windowed by the profile: a windowed
     * relation emits a pagination link carrying the relatedQuery filter/sort in plain
     * form, so the absence of any link mentioning the given fragment witnesses that
     * the window never fired (mirrors {@see RelationshipQueriesConformanceTestCase}).
     *
     * @param array<string, mixed> $relationshipObject
     */
    private static function assertWindowDidNotFire(array $relationshipObject, string $fragment): void
    {
        $links = $relationshipObject['links'] ?? [];
        self::assertIsArray($links);

        foreach (['first', 'prev', 'next', 'last'] as $rel) {
            $href = $links[$rel] ?? null;
            if ($href === null) {
                continue;
            }

            $value = \is_array($href) ? ($href['href'] ?? '') : $href;
            self::assertIsString($value);
            self::assertStringNotContainsString(
                $fragment,
                \rawurldecode($value),
                \sprintf('a not-windowed relation must not emit a "%s" link mirroring the relatedQuery', $rel),
            );
        }
    }
}
