<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\BinaryUuid\UuidBookEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\BinaryUuid\UuidLabelEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\BinaryUuid\UuidShelfEntity;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Resources keyed by a Doctrine `uuid` id stored as a 16-byte blob. The wire id is
 * the canonical UUID string, and every read and write path binds it in its binary
 * form. Doctrine-only: the in-memory provider has no database form to bind.
 */
abstract class BinaryUuidConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string COUNTS_ACCEPT = 'application/vnd.api+json;profile="' . CountableProfile::URI . '"';

    private const string QUERIES_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"';

    /**
     * @var array<string, string> fixture name => canonical wire id
     */
    private array $ids = [];

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $fiction = $this->shelf('fiction', 'Fiction');
        $this->shelf('empty', 'Empty');

        $classic = $this->label('classic', 'classic');
        $new = $this->label('new', 'new');
        $hidden = $this->label('hidden', 'hidden');

        $this->book('alpha', 'Alpha', $fiction, [$classic, $hidden]);
        $this->book('beta', 'Beta', $fiction, [$new]);
        $this->book('gamma', 'Gamma', null, []);

        $entityManager->flush();
        foreach (['fiction', 'empty', 'classic', 'new', 'hidden', 'alpha', 'beta', 'gamma'] as $name) {
            $entity = $this->seeded[$name];
            \assert($entity->id instanceof Uuid);
            $this->ids[$name] = $entity->id->toRfc4122();
        }
        $this->seeded = [];
        $entityManager->clear();
    }

    #[Test]
    public function theStoredKeyIsBinaryAndTheWireIdIsTheCanonicalString(): void
    {
        $connection = $this->entityManager()->getConnection();
        $stored = $connection->fetchOne('SELECT id FROM uuid_book WHERE title = ?', ['Alpha']);
        self::assertIsString($stored);
        self::assertSame(16, \strlen($stored), 'sqlite holds the id as 16 bytes');
        self::assertSame($this->ids['alpha'], Uuid::fromBinary($stored)->toRfc4122());
    }

    #[Test]
    public function fetchOneFindsTheRowAndRendersItsToOneLinkage(): void
    {
        $data = $this->dataOf($this->get('/books/' . $this->ids['alpha']));

        self::assertSame($this->ids['alpha'], $data['id'] ?? null);
        self::assertSame(['type' => 'shelves', 'id' => $this->ids['fiction']], $this->linkage($data, 'shelf'));
    }

    #[Test]
    public function anUnknownUuid404s(): void
    {
        self::assertSame(404, $this->handle('/books/' . Uuid::v4()->toRfc4122())->getStatusCode());
    }

    #[Test]
    public function aCollectionRendersEveryToOneLinkage(): void
    {
        $document = $this->decode($this->get('/books?sort=title'));
        $rows = $this->rows($document);

        self::assertSame([$this->ids['alpha'], $this->ids['beta'], $this->ids['gamma']], \array_column($rows, 'id'));
        self::assertSame(['type' => 'shelves', 'id' => $this->ids['fiction']], $this->linkage($rows[0], 'shelf'));
        self::assertSame(['type' => 'shelves', 'id' => $this->ids['fiction']], $this->linkage($rows[1], 'shelf'));
        self::assertNull($this->linkage($rows[2], 'shelf'));
    }

    #[Test]
    public function toManyLinkageOmitsTheScopedOutMember(): void
    {
        $data = $this->dataOf($this->get('/books/' . $this->ids['alpha']));

        self::assertSame([['type' => 'labels', 'id' => $this->ids['classic']]], $this->linkage($data, 'labels'));
    }

    #[Test]
    public function aComputedRelationNarrowsThroughTheScopedProbe(): void
    {
        $data = $this->dataOf($this->get('/books/' . $this->ids['alpha']));
        self::assertSame([['type' => 'labels', 'id' => $this->ids['classic']]], $this->linkage($data, 'pickedLabels'));

        $related = $this->rows($this->decode($this->get('/books/' . $this->ids['alpha'] . '/pickedLabels')));
        self::assertSame([$this->ids['classic']], \array_column($related, 'id'));
    }

    #[Test]
    public function includesResolveToOneAndToManyTargets(): void
    {
        $document = $this->decode($this->get('/books?sort=title&include=shelf,labels'));

        $included = $this->includedKeys($document);
        \sort($included);
        $expected = ['labels:' . $this->ids['classic'], 'labels:' . $this->ids['new'], 'shelves:' . $this->ids['fiction']];
        \sort($expected);
        self::assertSame($expected, $included);

        $rows = $this->rows($document);
        self::assertSame([['type' => 'labels', 'id' => $this->ids['new']]], $this->linkage($rows[1], 'labels'));
    }

    #[Test]
    public function anInverseToManyIncludePartitionsByParent(): void
    {
        $document = $this->decode($this->get('/shelves?sort=name&include=books'));
        $rows = $this->rows($document);

        self::assertSame([$this->ids['empty'], $this->ids['fiction']], \array_column($rows, 'id'));
        self::assertSame([], $this->linkage($rows[0], 'books'));
        self::assertEqualsCanonicalizing(
            [['type' => 'books', 'id' => $this->ids['alpha']], ['type' => 'books', 'id' => $this->ids['beta']]],
            $this->linkage($rows[1], 'books'),
        );
    }

    #[Test]
    public function theToOneRelatedEndpointResolvesTheTarget(): void
    {
        $data = $this->dataOf($this->get('/books/' . $this->ids['beta'] . '/shelf'));

        self::assertSame($this->ids['fiction'], $data['id'] ?? null);
    }

    #[Test]
    public function theToManyRelatedEndpointsScopeToTheParent(): void
    {
        $books = $this->rows($this->decode($this->get('/shelves/' . $this->ids['fiction'] . '/books?sort=title')));
        self::assertSame([$this->ids['alpha'], $this->ids['beta']], \array_column($books, 'id'));

        $labels = $this->rows($this->decode($this->get('/books/' . $this->ids['alpha'] . '/labels')));
        self::assertSame([$this->ids['classic']], \array_column($labels, 'id'));

        $linkage = $this->rows($this->decode($this->get('/shelves/' . $this->ids['fiction'] . '/relationships/books?sort=title')));
        self::assertSame([$this->ids['alpha'], $this->ids['beta']], \array_column($linkage, 'id'));
    }

    #[Test]
    public function withCountCountsPerParent(): void
    {
        $rows = $this->rows($this->decode($this->get('/shelves?sort=name&withCount=books', self::COUNTS_ACCEPT)));

        self::assertSame(0, $this->relationshipMeta($rows[0], 'books')['total'] ?? null);
        self::assertSame(2, $this->relationshipMeta($rows[1], 'books')['total'] ?? null);

        $book = $this->dataOf($this->get('/books/' . $this->ids['alpha'] . '?withCount=labels', self::COUNTS_ACCEPT));
        self::assertSame(1, $this->relationshipMeta($book, 'labels')['total'] ?? null);
    }

    #[Test]
    public function relatedQueryWindowsEachParent(): void
    {
        $rows = $this->rows($this->decode($this->get('/shelves?sort=name&include=books&relatedQuery[books][sort]=-title', self::QUERIES_ACCEPT)));

        self::assertSame(
            [['type' => 'books', 'id' => $this->ids['beta']], ['type' => 'books', 'id' => $this->ids['alpha']]],
            $this->linkage($rows[1], 'books'),
        );

        $books = $this->rows($this->decode($this->get('/books?sort=title&include=labels&relatedQuery[labels][sort]=-name', self::QUERIES_ACCEPT)));
        self::assertSame([['type' => 'labels', 'id' => $this->ids['classic']]], $this->linkage($books[0], 'labels'));
    }

    #[Test]
    public function aCreateWritesBinaryForeignKeys(): void
    {
        $response = $this->handle('/books', 'POST', ['data' => [
            'type' => 'books',
            'attributes' => ['title' => 'Delta'],
            'relationships' => [
                'shelf' => ['data' => ['type' => 'shelves', 'id' => $this->ids['fiction']]],
                'labels' => ['data' => [['type' => 'labels', 'id' => $this->ids['classic']], ['type' => 'labels', 'id' => $this->ids['new']]]],
            ],
        ]]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $created = $this->dataOf($response);
        $id = $created['id'] ?? null;
        self::assertIsString($id);
        self::assertTrue(Uuid::isValid($id));

        $this->entityManager()->clear();
        $data = $this->dataOf($this->get('/books/' . $id));
        self::assertSame(['type' => 'shelves', 'id' => $this->ids['fiction']], $this->linkage($data, 'shelf'));
        self::assertEqualsCanonicalizing(
            [['type' => 'labels', 'id' => $this->ids['classic']], ['type' => 'labels', 'id' => $this->ids['new']]],
            $this->linkage($data, 'labels'),
        );
    }

    #[Test]
    public function aCreateLinkingAScopedOutMember404s(): void
    {
        $response = $this->handle('/books', 'POST', ['data' => [
            'type' => 'books',
            'attributes' => ['title' => 'Delta'],
            'relationships' => [
                'labels' => ['data' => [['type' => 'labels', 'id' => $this->ids['hidden']]]],
            ],
        ]]);

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    public function anUpdateRewritesAttributesAndLinkage(): void
    {
        $response = $this->handle('/books/' . $this->ids['gamma'], 'PATCH', ['data' => [
            'type' => 'books',
            'id' => $this->ids['gamma'],
            'attributes' => ['title' => 'Gamma 2'],
            'relationships' => [
                'shelf' => ['data' => ['type' => 'shelves', 'id' => $this->ids['empty']]],
                'labels' => ['data' => [['type' => 'labels', 'id' => $this->ids['new']]]],
            ],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->entityManager()->clear();
        $data = $this->dataOf($this->get('/books/' . $this->ids['gamma']));
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('Gamma 2', $attributes['title'] ?? null);
        self::assertSame(['type' => 'shelves', 'id' => $this->ids['empty']], $this->linkage($data, 'shelf'));
        self::assertSame([['type' => 'labels', 'id' => $this->ids['new']]], $this->linkage($data, 'labels'));
    }

    #[Test]
    public function relationshipEndpointsWriteBinaryKeys(): void
    {
        $beta = '/books/' . $this->ids['beta'];

        $response = $this->handle($beta . '/relationships/shelf', 'PATCH', ['data' => ['type' => 'shelves', 'id' => $this->ids['empty']]]);
        self::assertContains($response->getStatusCode(), [200, 204], (string) $response->getContent());

        $response = $this->handle($beta . '/relationships/labels', 'POST', ['data' => [['type' => 'labels', 'id' => $this->ids['classic']]]]);
        self::assertContains($response->getStatusCode(), [200, 204], (string) $response->getContent());

        $response = $this->handle($beta . '/relationships/labels', 'DELETE', ['data' => [['type' => 'labels', 'id' => $this->ids['new']]]]);
        self::assertContains($response->getStatusCode(), [200, 204], (string) $response->getContent());

        $response = $this->handle('/shelves/' . $this->ids['empty'] . '/relationships/books', 'PATCH', ['data' => [
            ['type' => 'books', 'id' => $this->ids['beta']],
            ['type' => 'books', 'id' => $this->ids['gamma']],
        ]]);
        self::assertContains($response->getStatusCode(), [200, 204], (string) $response->getContent());

        $this->entityManager()->clear();
        $data = $this->dataOf($this->get($beta));
        self::assertSame(['type' => 'shelves', 'id' => $this->ids['empty']], $this->linkage($data, 'shelf'));
        self::assertSame([['type' => 'labels', 'id' => $this->ids['classic']]], $this->linkage($data, 'labels'));

        $books = $this->rows($this->decode($this->get('/shelves/' . $this->ids['empty'] . '/books?sort=title')));
        self::assertSame([$this->ids['beta'], $this->ids['gamma']], \array_column($books, 'id'));

        $hidden = $this->handle($beta . '/relationships/labels', 'POST', ['data' => [['type' => 'labels', 'id' => $this->ids['hidden']]]]);
        self::assertSame(404, $hidden->getStatusCode(), (string) $hidden->getContent());
    }

    #[Test]
    public function aDeleteRemovesTheRow(): void
    {
        self::assertSame(204, $this->handle('/books/' . $this->ids['gamma'], 'DELETE')->getStatusCode());
        self::assertSame(404, $this->handle('/books/' . $this->ids['gamma'])->getStatusCode());
    }

    /**
     * @var array<string, UuidShelfEntity|UuidBookEntity|UuidLabelEntity>
     */
    private array $seeded = [];

    private function shelf(string $key, string $name): UuidShelfEntity
    {
        $shelf = new UuidShelfEntity();
        $shelf->name = $name;
        $this->entityManager()->persist($shelf);

        return $this->seeded[$key] = $shelf;
    }

    private function label(string $key, string $name): UuidLabelEntity
    {
        $label = new UuidLabelEntity();
        $label->name = $name;
        $this->entityManager()->persist($label);

        return $this->seeded[$key] = $label;
    }

    /**
     * @param list<UuidLabelEntity> $labels
     */
    private function book(string $key, string $title, ?UuidShelfEntity $shelf, array $labels): void
    {
        $book = new UuidBookEntity();
        $book->title = $title;
        $book->shelf = $shelf;
        foreach ($labels as $label) {
            $book->labels->add($label);
        }
        $this->entityManager()->persist($book);
        $this->seeded[$key] = $book;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }

    private function get(string $path, string $accept = 'application/vnd.api+json'): Response
    {
        $response = $this->handle($path, 'GET', null, ['HTTP_ACCEPT' => $accept]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $response;
    }

    /**
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
     * @param array<string, mixed> $document
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $document): array
    {
        $rows = $document['data'] ?? null;
        self::assertIsArray($rows);

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function linkage(array $resource, string $relation): mixed
    {
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);
        $object = $relationships[$relation] ?? null;
        self::assertIsArray($object);
        self::assertArrayHasKey('data', $object, \sprintf('the %s relationship renders data', $relation));

        return $object['data'];
    }

    /**
     * @param array<string, mixed> $resource
     *
     * @return array<string, mixed>
     */
    private function relationshipMeta(array $resource, string $relation): array
    {
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);
        $object = $relationships[$relation] ?? null;
        self::assertIsArray($object);
        $meta = $object['meta'] ?? [];
        self::assertIsArray($meta);

        /** @var array<string, mixed> $meta */
        return $meta;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function includedKeys(array $document): array
    {
        $included = $document['included'] ?? [];
        self::assertIsArray($included);

        $keys = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            $keys[] = \sprintf('%s:%s', \is_string($resource['type'] ?? null) ? $resource['type'] : '', \is_string($resource['id'] ?? null) ? $resource['id'] : '');
        }

        return $keys;
    }
}
