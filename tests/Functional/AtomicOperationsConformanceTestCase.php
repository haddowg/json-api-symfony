<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The Slice-C acceptance suite for the JSON:API Atomic Operations extension: a real
 * `POST /operations` batch runs end-to-end on BOTH providers (the in-memory witness
 * and the Doctrine-sqlite kernel), all-or-nothing, with local-id (`lid`)
 * cross-references — each subclass differing only in the kernel it names.
 *
 * The fixtures are the shared `articles`/`authors`/`comments` relationship graph
 * (article 1 → author 1, comments [1,2]; 5 articles, 2 authors, 5 comments seeded),
 * so a batch can create a resource and reference it by `lid` in a `ref` and/or in
 * relationship linkage, and a forced failure proves nothing committed.
 *
 * @see https://jsonapi.org/ext/atomic/
 */
abstract class AtomicOperationsConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    #[Group('spec:atomic')]
    public function aBatchCreatesAResourceAndALaterOperationReferencesItByLid(): void
    {
        // Op 0: create an author with lid "newAuthor".
        // Op 1: create an article that references that author by {type, lid} in its
        // relationship linkage, AND a `ref` is not needed for a create.
        $response = $this->atomic([
            [
                'op' => 'add',
                'data' => [
                    'type' => 'authors',
                    'lid' => 'newAuthor',
                    'attributes' => ['name' => 'Margaret Hamilton'],
                ],
            ],
            [
                'op' => 'add',
                'data' => [
                    'type' => 'articles',
                    'attributes' => ['title' => 'Apollo guidance', 'body' => 'On the moon.', 'category' => 'news'],
                    'relationships' => [
                        'author' => ['data' => ['type' => 'authors', 'lid' => 'newAuthor']],
                    ],
                ],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        // The response Content-Type advertises the atomic extension.
        self::assertStringContainsString('ext="https://jsonapi.org/ext/atomic"', (string) $response->headers->get('Content-Type'));

        $results = $this->results($response);
        self::assertCount(2, $results);

        // Result 0: the created author with its real, store-assigned id (3 — past the
        // two seeded authors).
        $author = $results[0]['data'] ?? null;
        self::assertIsArray($author);
        self::assertSame('authors', $author['type'] ?? null);
        $authorId = $author['id'] ?? null;
        self::assertSame('3', $authorId);

        // Result 1: the created article, whose `author` linkage resolved the lid to
        // the new author's real id.
        $article = $results[1]['data'] ?? null;
        self::assertIsArray($article);
        self::assertSame('articles', $article['type'] ?? null);
        $articleId = $article['id'] ?? null;
        self::assertIsString($articleId);

        // Both committed: a follow-up read returns the new article wired to the new author.
        self::assertSame(
            ['type' => 'authors', 'id' => $authorId],
            $this->linkageOf('/articles/' . $articleId . '/relationships/author'),
        );
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aBatchReferencesACreatedResourceByLidInARef(): void
    {
        // Op 0: create an article with lid "draft".
        // Op 1: update that article BY its lid in the `ref`.
        $response = $this->atomic([
            [
                'op' => 'add',
                'data' => [
                    'type' => 'articles',
                    'lid' => 'draft',
                    'attributes' => ['title' => 'Draft title', 'body' => 'WIP.', 'category' => 'guide'],
                ],
            ],
            [
                'op' => 'update',
                'ref' => ['type' => 'articles', 'lid' => 'draft'],
                'data' => [
                    'type' => 'articles',
                    'attributes' => ['title' => 'Final title'],
                ],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $results = $this->results($response);
        self::assertCount(2, $results);

        $created = $results[0]['data'] ?? null;
        self::assertIsArray($created);
        $id = $created['id'] ?? null;
        self::assertIsString($id);

        // The update resolved the lid in the `ref` and applied the new title.
        $updated = $results[1]['data'] ?? null;
        self::assertIsArray($updated);
        self::assertSame($id, $updated['id'] ?? null);
        self::assertSame('Final title', $this->member($updated, 'attributes', 'title'));

        // Persisted: the re-read article carries the final title.
        self::assertSame('Final title', $this->attributesOf($this->handle('/articles/' . $id))['title'] ?? null);
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aBatchMixesAddUpdateAndRemoveInOrder(): void
    {
        $response = $this->atomic([
            [
                'op' => 'add',
                'data' => [
                    'type' => 'authors',
                    'attributes' => ['name' => 'Katherine Johnson'],
                ],
            ],
            [
                'op' => 'update',
                'ref' => ['type' => 'articles', 'id' => '1'],
                'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['title' => 'Edited in a batch']],
            ],
            [
                'op' => 'remove',
                'ref' => ['type' => 'articles', 'id' => '2'],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $results = $this->results($response);
        self::assertCount(3, $results);

        // The add returns the created author; the update returns the edited article;
        // the remove returns an empty result object.
        self::assertSame('authors', $this->member($results[0], 'data', 'type'));
        self::assertSame('Edited in a batch', $this->member($results[1], 'data', 'attributes', 'title'));
        self::assertSame([], $results[2]);

        // Pin the wire JSON type: the remove's empty result must serialize as the
        // result object `{}`, not the JSON array `[]` an associative decode collapses
        // it to. The batch ends `...},{}]`, so the trailing `{}` is unambiguous.
        self::assertStringEndsWith('{}]', $this->resultsLiteral($response));

        // All three committed.
        self::assertSame('Edited in a batch', $this->attributesOf($this->handle('/articles/1'))['title'] ?? null);
        self::assertSame(404, $this->handle('/articles/2')->getStatusCode());
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aRemoveOfAResourceCreatedEarlierInTheSameBatch(): void
    {
        $response = $this->atomic([
            [
                'op' => 'add',
                'data' => [
                    'type' => 'authors',
                    'lid' => 'temp',
                    'attributes' => ['name' => 'Temporary'],
                ],
            ],
            [
                'op' => 'remove',
                'ref' => ['type' => 'authors', 'lid' => 'temp'],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $results = $this->results($response);
        self::assertCount(2, $results);

        $id = $this->member($results[0], 'data', 'id');
        self::assertIsString($id);
        self::assertSame([], $results[1]);

        // Created-then-removed in one batch: the author does not exist after.
        self::assertSame(404, $this->handle('/authors/' . $id)->getStatusCode());
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aRelationshipOperationLinkageReferencesALid(): void
    {
        // Op 0: create a comment with lid "fresh".
        // Op 1: add it to article 1's `comments` to-many by {type, lid} linkage.
        $response = $this->atomic([
            [
                'op' => 'add',
                'data' => [
                    'type' => 'comments',
                    'lid' => 'fresh',
                    'attributes' => ['body' => 'A fresh comment.'],
                ],
            ],
            [
                'op' => 'add',
                'ref' => ['type' => 'articles', 'id' => '1', 'relationship' => 'comments'],
                'data' => [
                    ['type' => 'comments', 'lid' => 'fresh'],
                ],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $results = $this->results($response);
        self::assertCount(2, $results);

        $commentId = $this->member($results[0], 'data', 'id');
        self::assertSame('6', $commentId);

        // The new comment was added to article 1's comments (seeded with 1, 2).
        $ids = $this->identifierIdsOf('/articles/1/relationships/comments');
        self::assertContains('6', $ids);
        self::assertContains('1', $ids);
        self::assertContains('2', $ids);
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aLaterFailureRollsBackTheWholeBatch(): void
    {
        // Op 0 succeeds (a valid update of article 1); op 1 targets a missing article,
        // so the WHOLE batch must roll back — op 0's change must not persist.
        $response = $this->atomic([
            [
                'op' => 'update',
                'ref' => ['type' => 'articles', 'id' => '1'],
                'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['title' => 'Should be rolled back']],
            ],
            [
                'op' => 'update',
                'ref' => ['type' => 'articles', 'id' => '404'],
                'data' => ['type' => 'articles', 'id' => '404', 'attributes' => ['title' => 'No such article']],
            ],
        ]);

        // A single error document with the failing operation's pointer prefix.
        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());

        $errors = $this->errors($response);
        self::assertNotEmpty($errors);
        self::assertSame('/atomic:operations/1', $this->member($errors[0], 'source', 'pointer'));

        // Nothing committed: article 1 keeps its original title (op 0 rolled back).
        self::assertSame('JSON:API in PHP', $this->attributesOf($this->handle('/articles/1'))['title'] ?? null);
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aForwardLidReferenceIs400AtTheOperationPointer(): void
    {
        // Op 0 references a lid that is only registered by op 1 (a forward reference).
        $response = $this->atomic([
            [
                'op' => 'add',
                'data' => [
                    'type' => 'articles',
                    'attributes' => ['title' => 'Early', 'body' => 'b', 'category' => 'news'],
                    'relationships' => [
                        'author' => ['data' => ['type' => 'authors', 'lid' => 'later']],
                    ],
                ],
            ],
            [
                'op' => 'add',
                'data' => ['type' => 'authors', 'lid' => 'later', 'attributes' => ['name' => 'Too late']],
            ],
        ]);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());

        $errors = $this->errors($response);
        self::assertSame('LOCAL_ID_NOT_FOUND', $errors[0]['code'] ?? null);
        self::assertSame('/atomic:operations/0', $this->member($errors[0], 'source', 'pointer'));

        // Nothing committed: no third author exists.
        self::assertSame(404, $this->handle('/authors/3')->getStatusCode());
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aDuplicateLidIs400(): void
    {
        $response = $this->atomic([
            ['op' => 'add', 'data' => ['type' => 'authors', 'lid' => 'dup', 'attributes' => ['name' => 'One']]],
            ['op' => 'add', 'data' => ['type' => 'authors', 'lid' => 'dup', 'attributes' => ['name' => 'Two']]],
        ]);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());

        $errors = $this->errors($response);
        self::assertSame('LOCAL_ID_CONFLICT', $errors[0]['code'] ?? null);
        self::assertSame('/atomic:operations/1', $this->member($errors[0], 'source', 'pointer'));

        // Nothing committed: the first author did not survive the rolled-back batch.
        self::assertSame(404, $this->handle('/authors/3')->getStatusCode());
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anHrefTargetedOperationResolves(): void
    {
        $response = $this->atomic([
            [
                'op' => 'update',
                'href' => '/articles/1',
                'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['title' => 'Updated via href']],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('Updated via href', $this->member($this->results($response)[0], 'data', 'attributes', 'title'));

        self::assertSame('Updated via href', $this->attributesOf($this->handle('/articles/1'))['title'] ?? null);
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anEmptyBatchIs400(): void
    {
        $response = $this->atomic([]);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('ATOMIC_OPERATIONS_INVALID', $this->errors($response)[0]['code'] ?? null);
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aGuardDeniedOperationRollsBackTheWholeBatch(): void
    {
        // Op 0 succeeds; op 1 replaces a `cannotReplace` relationship (`lockedAuthor`)
        // → 403, so the whole batch rolls back.
        $response = $this->atomic([
            [
                'op' => 'update',
                'ref' => ['type' => 'articles', 'id' => '1'],
                'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['title' => 'Rolled back by guard']],
            ],
            [
                'op' => 'update',
                'ref' => ['type' => 'articles', 'id' => '1', 'relationship' => 'lockedAuthor'],
                'data' => ['type' => 'authors', 'id' => '2'],
            ],
        ]);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        // The guard's error carries its own pointer (the relationship member), which the
        // loop prefixes with the failing operation index.
        self::assertSame(
            '/atomic:operations/1/data/relationships/lockedAuthor',
            $this->member($this->errors($response)[0], 'source', 'pointer'),
        );

        // Op 0 rolled back: article 1 keeps its original title.
        self::assertSame('JSON:API in PHP', $this->attributesOf($this->handle('/articles/1'))['title'] ?? null);
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aRelationshipRollbackRestoresAssociationAndRelatedObjectIdentity(): void
    {
        // The cross-store identity case (Slice C carry-forward): a relationship mutation
        // inside a batch, then a FORCED rollback (the next op fails), then assert the
        // association AND the related-object graph are restored to the pre-batch state.
        //
        // Pre-state: article 1 → author 1, comments [1, 2].
        $response = $this->atomic([
            // Replace article 1's author with author 2.
            [
                'op' => 'update',
                'ref' => ['type' => 'articles', 'id' => '1', 'relationship' => 'author'],
                'data' => ['type' => 'authors', 'id' => '2'],
            ],
            // Replace article 1's comments with [comment 4].
            [
                'op' => 'update',
                'ref' => ['type' => 'articles', 'id' => '1', 'relationship' => 'comments'],
                'data' => [['type' => 'comments', 'id' => '4']],
            ],
            // Force a rollback: target a missing article.
            [
                'op' => 'remove',
                'ref' => ['type' => 'articles', 'id' => '404'],
            ],
        ]);

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());

        // The association is restored: article 1 → author 1, comments [1, 2] — exactly
        // the pre-batch graph. On the in-memory provider this only holds if the
        // cross-store snapshot preserved object identity on restore (the article's
        // author reference points at the LIVE author-1 object in the authors store, and
        // its comments at the live comment objects), so a parent → related read sees the
        // restored graph rather than a severed clone.
        self::assertSame(
            ['type' => 'authors', 'id' => '1'],
            $this->linkageOf('/articles/1/relationships/author'),
        );
        self::assertSame(
            ['1', '2'],
            $this->identifierIdsOf('/articles/1/relationships/comments'),
        );

        // The author and comments themselves are intact (renderable through the related
        // endpoint — the read traverses the restored cross-store object graph).
        $related = $this->fetchDocument('/articles/1/author');
        self::assertSame('authors', $this->member($related, 'data', 'type'));
        self::assertSame('1', $this->member($related, 'data', 'id'));
        self::assertSame('Ada Lovelace', $this->member($related, 'data', 'attributes', 'name'));
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anAddOfAnUnknownTypeIs404NotAServerError(): void
    {
        // A batch op targeting a type no data source can write is a client error — there
        // is no routing step inside a batch to 404 it first, so the executor's pre-flight
        // must refuse it cleanly (404), never let the registry's wiring-error throw escape
        // as a 500.
        $response = $this->atomic([
            [
                'op' => 'add',
                'data' => ['type' => 'nonexistent', 'attributes' => ['name' => 'Nobody']],
            ],
        ]);

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('ATOMIC_TARGET_TYPE_UNKNOWN', $this->errors($response)[0]['code'] ?? null);
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anUpdateRefOfAnUnknownTypeIs404NotAServerError(): void
    {
        $response = $this->atomic([
            [
                'op' => 'update',
                'ref' => ['type' => 'nope', 'id' => '1'],
                'data' => ['type' => 'nope', 'id' => '1', 'attributes' => ['name' => 'X']],
            ],
        ]);

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('ATOMIC_TARGET_TYPE_UNKNOWN', $this->errors($response)[0]['code'] ?? null);
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aPlainContentTypeWithoutTheAtomicExtIs415(): void
    {
        // The Atomic Operations endpoint REQUIRES the atomic `ext` media-type parameter
        // on the request `Content-Type`; a plain `application/vnd.api+json` is a
        // 415 (the extension was not applied). The 415 document does NOT advertise the
        // ext — the extension was never successfully negotiated.
        $response = $this->atomic(
            [['op' => 'add', 'data' => ['type' => 'authors', 'attributes' => ['name' => 'Nope']]]],
            contentType: 'application/vnd.api+json',
        );

        self::assertSame(415, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringNotContainsString('ext=', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aPlainAcceptWithoutTheAtomicExtIs406(): void
    {
        // The endpoint also REQUIRES the atomic `ext` on `Accept`; a plain
        // `application/vnd.api+json` Accept is a 406. The 406 document does NOT advertise
        // the ext — content negotiation failed, so the extension was not applied.
        $response = $this->atomic(
            [['op' => 'add', 'data' => ['type' => 'authors', 'attributes' => ['name' => 'Nope']]]],
            accept: 'application/vnd.api+json',
        );

        self::assertSame(406, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringNotContainsString('ext=', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aSuccessfulBatchAdvertisesTheAtomicExtOnContentType(): void
    {
        $response = $this->atomic([
            [
                'op' => 'update',
                'ref' => ['type' => 'articles', 'id' => '1'],
                'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['title' => 'With ext']],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString(
            'ext="https://jsonapi.org/ext/atomic"',
            (string) $response->headers->get('Content-Type'),
        );
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aPreFlightErrorAdvertisesTheAtomicExtOnContentType(): void
    {
        // A pre-flight refusal (an unknown target type → 404) is raised BEFORE the loop,
        // so it renders through the ExceptionListener rather than the AtomicLoop. It must
        // still advertise the atomic ext — the extension WAS successfully negotiated, so
        // the error document is produced under it (Slice D fix).
        $response = $this->atomic([
            ['op' => 'add', 'data' => ['type' => 'nonexistent', 'attributes' => ['name' => 'Nobody']]],
        ]);

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString(
            'ext="https://jsonapi.org/ext/atomic"',
            (string) $response->headers->get('Content-Type'),
        );
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aParseErrorAdvertisesTheAtomicExtOnContentType(): void
    {
        // An empty batch is a parse-time 400 raised BEFORE the loop (the parser rejects
        // an empty `atomic:operations`), rendered through the ExceptionListener. The
        // extension was negotiated, so the error advertises the atomic ext (Slice D fix).
        $response = $this->atomic([]);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString(
            'ext="https://jsonapi.org/ext/atomic"',
            (string) $response->headers->get('Content-Type'),
        );
    }

    #[Test]
    #[Group('spec:atomic')]
    public function includeAndFieldsQueryParamsAreNeitherHonouredNorRejected(): void
    {
        // The atomic endpoint does NOT honour ?include or sparse ?fields — a result
        // object is `{data, meta}` only (no compound document, no `included`). An
        // ?include/?fields param on /operations is a recognized JSON:API query-param
        // name, so it is NOT rejected (no spurious 400); it is simply not processed —
        // the batch succeeds and the response carries no top-level `included` (bundle
        // ADR 0088, the deliberate "not part of the atomic flow" choice).
        $response = $this->atomic(
            [
                [
                    'op' => 'update',
                    'ref' => ['type' => 'articles', 'id' => '1'],
                    'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['title' => 'Ignoring include']],
                ],
            ],
            query: 'include=author&fields[articles]=title',
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        // The batch ran (the update applied) and the document has no `included` member —
        // the query params were not processed.
        self::assertSame('Ignoring include', $this->member($this->results($response)[0], 'data', 'attributes', 'title'));
        self::assertArrayNotHasKey('included', $this->decode($response));

        // An UNRECOGNIZED query param is still the endpoint's normal 400 (the existing
        // strict-query-param behaviour applies to /operations like any JSON:API route).
        $rejected = $this->atomic(
            [
                [
                    'op' => 'update',
                    'ref' => ['type' => 'articles', 'id' => '1'],
                    'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['title' => 'Bad param']],
                ],
            ],
            query: 'bogus=1',
        );

        self::assertSame(400, $rejected->getStatusCode(), (string) $rejected->getContent());
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Issues a `POST /operations` atomic batch, carrying the atomic `ext` media-type
     * parameter on both `Content-Type` and `Accept` as the extension requires. An
     * optional `$query` appends a query string to the endpoint (e.g. `include=author`)
     * to characterize that the atomic flow does not honour — nor spuriously reject —
     * `?include`/`?fields`.
     *
     * @param list<array<string, mixed>> $operations
     */
    protected function atomic(array $operations, ?string $contentType = null, ?string $accept = null, ?string $query = null): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $ext = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';

        $server = [
            'CONTENT_TYPE' => $contentType ?? $ext,
            'HTTP_ACCEPT' => $accept ?? $ext,
        ];

        $content = \json_encode(['atomic:operations' => $operations], \JSON_THROW_ON_ERROR);

        $uri = '/operations' . ($query !== null ? '?' . $query : '');
        $request = Request::create($uri, 'POST', server: $server, content: $content);
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);

        // Rebalance the global error/exception-handler stack the kernel pushed, the
        // same way the base handle() does — PHPUnit's strict mode flags any imbalance.
        $this->restoreHandlers();

        return $response;
    }

    /**
     * The decoded `atomic:results` array.
     *
     * @return list<array<string, mixed>>
     */
    protected function results(Response $response): array
    {
        $results = $this->decode($response)['atomic:results'] ?? null;
        self::assertIsArray($results);

        /** @var list<array<string, mixed>> $results */
        return \array_values($results);
    }

    /**
     * The raw `atomic:results` array literal as it appears on the wire — used to pin
     * the JSON type of each result (an empty result is a JSON object `{}`, never the
     * array `[]` an associative decode collapses it to). Returns the substring from
     * the opening `[` after `"atomic:results":` to its matching `]`.
     */
    protected function resultsLiteral(Response $response): string
    {
        $body = (string) $response->getContent();

        $start = \strpos($body, '"atomic:results":');
        self::assertNotFalse($start, $body);
        $open = \strpos($body, '[', $start);
        self::assertNotFalse($open, $body);

        $depth = 0;
        for ($i = $open, $len = \strlen($body); $i < $len; ++$i) {
            $char = $body[$i];
            if ($char === '[') {
                ++$depth;
            } elseif ($char === ']' && --$depth === 0) {
                return \substr($body, $open, $i - $open + 1);
            }
        }

        self::fail('Unterminated atomic:results array in: ' . $body);
    }

    /**
     * The decoded `errors` array.
     *
     * @return list<array<string, mixed>>
     */
    protected function errors(Response $response): array
    {
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);

        /** @var list<array<string, mixed>> $errors */
        return \array_values($errors);
    }

    /**
     * The `data.attributes` of a primary-resource document, narrowed.
     *
     * @return array<string, mixed>
     */
    protected function attributesOf(Response $response): array
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }

    /**
     * The decoded document at a path (a relationship-linkage or related read).
     *
     * @return array<string, mixed>
     */
    protected function fetchDocument(string $path): array
    {
        return $this->decode($this->handle($path));
    }

    /**
     * The `data` linkage of a relationship-linkage read (a `{type, id}` object or null).
     *
     * @return array<string, mixed>|null
     */
    protected function linkageOf(string $path): ?array
    {
        $data = $this->fetchDocument($path)['data'] ?? null;
        if ($data === null) {
            return null;
        }
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * The sorted list of resource-identifier ids of a to-many relationship-linkage read.
     *
     * @return list<string>
     */
    protected function identifierIdsOf(string $path): array
    {
        $data = $this->fetchDocument($path)['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            $id = $identifier['id'] ?? null;
            if (\is_string($id)) {
                $ids[] = $id;
            }
        }

        \sort($ids);

        return $ids;
    }

    /**
     * Walks a nested-array path (`$array['a']['b']['c']`), asserting each level is an
     * array, and returns the leaf value (or null when a level is absent) — so a nested
     * document member can be read without tripping PHPStan's mixed-offset rule.
     *
     * @param array<string, mixed> $array
     */
    protected function member(array $array, string ...$keys): mixed
    {
        $value = $array;
        foreach ($keys as $key) {
            self::assertIsArray($value);
            $value = $value[$key] ?? null;
        }

        return $value;
    }
}
