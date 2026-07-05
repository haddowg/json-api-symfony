<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The write half of the dual-provider conformance contract: identical
 * create/update/delete assertions run against the in-memory kernel
 * ({@see InMemoryWriteTest}) and the Doctrine-sqlite kernel
 * ({@see DoctrineWriteTest}), so a failure on one provider but not the other
 * localizes to that persister's execution. Each subclass differs only in the
 * kernel it names (and the Doctrine one's schema + seed in `afterBoot()`).
 *
 * Ids are server-generated (the resource accepts no client id), so the create
 * assertions read the id back from the response rather than asserting a literal.
 */
abstract class WriteConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    #[Group('spec:crud')]
    public function creatingAResourceReturns201WithLocationAndTheCreatedDocument(): void
    {
        $response = $this->handle('/articles', 'POST', [
            'data' => [
                'type' => 'articles',
                'attributes' => [
                    'title' => 'A brand new article',
                    'body' => 'Fresh content.',
                    'category' => 'news',
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());

        $data = $this->dataOf($response);
        self::assertSame('articles', $data['type'] ?? null);

        // The id is store-provided: the create omits `data.id` and the store assigns
        // the next sequential id past the five seeded rows. It is predictable (6) on
        // BOTH providers — the in-memory sequence and the Doctrine auto-increment both
        // continue past the seed — and round-trips through the response and a re-fetch.
        $id = $data['id'] ?? null;
        self::assertSame('6', $id);

        self::assertSame('https://example.test/articles/' . $id, $response->headers->get('Location'));

        // The created resource carries its convention self link: the persister has
        // assigned the id by render time, so the resource self is present and equal
        // to the Location (core ADR 0054).
        $links = $data['links'] ?? null;
        self::assertIsArray($links);
        self::assertSame('https://example.test/articles/' . $id, $links['self'] ?? null);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('A brand new article', $attributes['title'] ?? null);
        self::assertSame('news', $attributes['category'] ?? null);

        // The created resource is persisted: a follow-up read returns it.
        $fetched = $this->attributesOf($this->handle('/articles/' . $id));
        self::assertSame('A brand new article', $fetched['title'] ?? null);
        self::assertSame('Fresh content.', $fetched['body'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function updatingAResourceReturns200AndAppliesOnlyTheSuppliedAttributes(): void
    {
        $response = $this->handle('/articles/1', 'PATCH', [
            'data' => [
                'type' => 'articles',
                'id' => '1',
                'attributes' => ['title' => 'An edited title'],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode());

        $attributes = $this->attributesOf($response);
        self::assertSame('An edited title', $attributes['title'] ?? null);
        // A partial update leaves the unsupplied attributes untouched (article 1
        // is in the `guide` category in the fixtures).
        self::assertSame('guide', $attributes['category'] ?? null);

        // The change is persisted.
        $fetched = $this->attributesOf($this->handle('/articles/1'));
        self::assertSame('An edited title', $fetched['title'] ?? null);
        self::assertSame('guide', $fetched['category'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function anUndeclaredAttributeInAWriteBodyIsSilentlyIgnored(): void
    {
        // Allow-list hydration: an attribute the resource did not declare (the classic
        // mass-assignment over-post) is dropped — never written, and the declared-only
        // resource the engine renders never surfaces it.
        $response = $this->handle('/articles/1', 'PATCH', [
            'data' => [
                'type' => 'articles',
                'id' => '1',
                'attributes' => [
                    'title' => 'Edited via allow-list',
                    'isAdmin' => true,
                    'undeclared' => 'nope',
                ],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $attributes = $this->attributesOf($response);
        self::assertSame('Edited via allow-list', $attributes['title'] ?? null);
        self::assertArrayNotHasKey('isAdmin', $attributes);
        self::assertArrayNotHasKey('undeclared', $attributes);
    }

    #[Test]
    #[Group('spec:crud')]
    public function deletingAResourceReturns204AndThenItIsGone(): void
    {
        $response = $this->handle('/articles/1', 'DELETE');

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getContent());

        self::assertSame(404, $this->handle('/articles/1')->getStatusCode());
    }

    #[Test]
    #[Group('spec:crud')]
    public function updatingAMissingResourceReturns404(): void
    {
        $response = $this->handle('/articles/404', 'PATCH', [
            'data' => [
                'type' => 'articles',
                'id' => '404',
                'attributes' => ['title' => 'Does not matter'],
            ],
        ]);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    #[Group('spec:crud')]
    public function deletingAMissingResourceReturns404(): void
    {
        self::assertSame(404, $this->handle('/articles/404', 'DELETE')->getStatusCode());
    }

    #[Test]
    #[Group('spec:crud')]
    public function patchingWithABodyIdMismatchingTheUrlReturns409(): void
    {
        // Core (OperationFactory::fromRequest, the id half of the spec's "type and id must
        // match the endpoint" MUST): a resource PATCH whose `data.id` is present and differs
        // from the endpoint id is a 409 conflict — code RESOURCE_ID_CONFLICT at /data/id.
        // The bundle inherits it verbatim: it resolves every operation through core's factory
        // in the request listener, so the mismatch is caught before dispatch on both providers.
        $response = $this->handle('/articles/1', 'PATCH', [
            'data' => [
                'type' => 'articles',
                'id' => '2',
                'attributes' => ['title' => 'A mismatched id'],
            ],
        ]);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        [$code, $pointer] = $this->firstError($response);
        self::assertSame('RESOURCE_ID_CONFLICT', $code);
        self::assertSame('/data/id', $pointer);
    }

    #[Test]
    #[Group('spec:crud')]
    public function patchingWithAMatchingBodyIdSucceedsAndAnAbsentBodyIdIsNotAConflict(): void
    {
        // The 409 fires ONLY on a genuine mismatch. A body id EQUAL to the URL id is the
        // ordinary update path — a 200.
        $matching = $this->handle('/articles/1', 'PATCH', [
            'data' => [
                'type' => 'articles',
                'id' => '1',
                'attributes' => ['title' => 'A matching id'],
            ],
        ]);
        self::assertSame(200, $matching->getStatusCode(), (string) $matching->getContent());

        // An ABSENT body id is "a separate concern the hydrator owns" (core's own words):
        // the conflict check only fires when a body id is present, so an omitted id is
        // never a 409 — the update proceeds against the URL-targeted resource as a 200.
        $absent = $this->handle('/articles/1', 'PATCH', [
            'data' => [
                'type' => 'articles',
                'attributes' => ['title' => 'An absent id'],
            ],
        ]);
        self::assertSame(200, $absent->getStatusCode(), (string) $absent->getContent());
        self::assertSame('1', $this->dataOf($absent)['id'] ?? null);
    }

    #[Test]
    #[Group('spec:atomic-operations')]
    public function creatingWithALocalIdOnThePrimaryResourceReturns400(): void
    {
        // `lid` is an Atomic Operations member; a standalone create carrying one is a
        // 400 (not silently ignored), on both providers (core ADR 0104).
        $response = $this->handle('/articles', 'POST', [
            'data' => [
                'type' => 'articles',
                'lid' => 'a1',
                'attributes' => ['title' => 'x', 'body' => 'y', 'category' => 'news'],
            ],
        ]);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        [$code, $pointer] = $this->firstError($response);
        self::assertSame('LOCAL_ID_NOT_SUPPORTED', $code);
        self::assertSame('/data/lid', $pointer);
    }

    #[Test]
    #[Group('spec:atomic-operations')]
    public function creatingWithALocalIdInEmbeddedLinkageReturns400(): void
    {
        $response = $this->handle('/articles', 'POST', [
            'data' => [
                'type' => 'articles',
                'attributes' => ['title' => 'x', 'body' => 'y', 'category' => 'news'],
                'relationships' => ['author' => ['data' => ['type' => 'authors', 'lid' => 'p1']]],
            ],
        ]);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        [$code, $pointer] = $this->firstError($response);
        self::assertSame('LOCAL_ID_NOT_SUPPORTED', $code);
        self::assertSame('/data/relationships/author/data/lid', $pointer);
    }

    #[Test]
    #[Group('spec:atomic-operations')]
    public function mutatingARelationshipWithALocalIdLinkageReturns400(): void
    {
        // The relationship-endpoint linkage parser also rejects a `lid` — this path
        // skips top-level-member validation, so the parser check is what guards it.
        $response = $this->handle('/articles/1/relationships/author', 'PATCH', [
            'data' => ['type' => 'authors', 'lid' => 'p1'],
        ]);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        [$code, $pointer] = $this->firstError($response);
        self::assertSame('LOCAL_ID_NOT_SUPPORTED', $code);
        self::assertSame('/data/lid', $pointer);
    }

    /**
     * The `code` and `source.pointer` of the first error in the document.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function firstError(Response $response): array
    {
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertArrayHasKey(0, $errors);

        $error = $errors[0];
        self::assertIsArray($error);

        $code = $error['code'] ?? null;
        self::assertIsString($code);

        $source = $error['source'] ?? null;
        self::assertIsArray($source);
        $pointer = $source['pointer'] ?? null;
        self::assertIsString($pointer);

        return [$code, $pointer];
    }

    /**
     * The decoded document's primary `data` object, narrowed for offset access.
     *
     * @return array<string, mixed>
     */
    private function dataOf(Response $response): array
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * The decoded document's `data.attributes`, narrowed for offset access.
     *
     * @return array<string, mixed>
     */
    private function attributesOf(Response $response): array
    {
        $attributes = $this->dataOf($response)['attributes'] ?? null;
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }
}
