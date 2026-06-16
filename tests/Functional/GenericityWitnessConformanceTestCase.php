<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The capstone genericity proof (ADR 0021): the `tags` type is declared with
 * nothing but a resource class (+ a Doctrine entity / in-memory POJO). No per-type
 * handler, route, serializer or persister code exists for it, yet the full JSON:API
 * endpoint set works — CRUD plus the relationship-read endpoints — identically on
 * the in-memory ({@see InMemoryGenericityWitnessTest}) and Doctrine-sqlite
 * ({@see DoctrineGenericityWitnessTest}) providers. Relationship *mutation*
 * genericity is already covered exhaustively by the `articles` suite; here the
 * unlinked to-one renders `data: null` on both providers with no seeded linkage.
 *
 * Each subclass differs only in the kernel it names (and the Doctrine one's schema
 * + seed in `afterBoot()`). The two seed tags are id `1` (name "PHP") and id `2`
 * (name "Testing"), both with no linked article.
 */
abstract class GenericityWitnessConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    #[Group('spec:crud')]
    public function fetchingTheCollectionReturnsEveryTag(): void
    {
        $data = $this->fetch('/tags')['data'] ?? null;
        self::assertIsArray($data);
        self::assertCount(2, $data);

        $first = $data[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('tags', $first['type'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function fetchingASingleTagReturnsIt(): void
    {
        $response = $this->handle('/tags/1');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('PHP', $this->attributesOf($response)['name'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingATagReturns201WithLocationAndPersists(): void
    {
        $response = $this->handle('/tags', 'POST', [
            'data' => [
                'type' => 'tags',
                'attributes' => ['name' => 'Symfony'],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());

        $data = $this->dataOf($response);
        self::assertSame('tags', $data['type'] ?? null);

        // Store-provided id: the create omits `data.id` and the store assigns the next
        // sequential id past the two seeded tags — a predictable 3 on both providers.
        $id = $data['id'] ?? null;
        self::assertSame('3', $id);

        self::assertSame('https://example.test/tags/' . $id, $response->headers->get('Location'));

        // The created tag is persisted: a follow-up read returns it.
        $fetched = $this->attributesOf($this->handle('/tags/' . $id));
        self::assertSame('Symfony', $fetched['name'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function updatingATagReturns200AndPersists(): void
    {
        $response = $this->handle('/tags/1', 'PATCH', [
            'data' => [
                'type' => 'tags',
                'id' => '1',
                'attributes' => ['name' => 'PHP 8'],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('PHP 8', $this->attributesOf($response)['name'] ?? null);

        $fetched = $this->attributesOf($this->handle('/tags/1'));
        self::assertSame('PHP 8', $fetched['name'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function deletingATagReturns204AndThenItIsGone(): void
    {
        self::assertSame(204, $this->handle('/tags/1', 'DELETE')->getStatusCode());
        self::assertSame(404, $this->handle('/tags/1')->getStatusCode());
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theRelatedEndpointRendersNullForAnUnlinkedToOne(): void
    {
        $response = $this->handle('/tags/2/article');

        self::assertSame(200, $response->getStatusCode());
        $document = $this->decode($response);
        self::assertArrayHasKey('data', $document);
        self::assertNull($document['data']);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theRelationshipLinkageEndpointRendersNullForAnUnlinkedToOne(): void
    {
        $response = $this->handle('/tags/2/relationships/article');

        self::assertSame(200, $response->getStatusCode());
        $document = $this->decode($response);
        self::assertArrayHasKey('data', $document);
        self::assertNull($document['data']);
    }

    /**
     * Issues a `GET`, asserts a `200`, and returns the decoded document.
     *
     * @return array<string, mixed>
     */
    private function fetch(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode());

        return $this->decode($response);
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
