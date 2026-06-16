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
