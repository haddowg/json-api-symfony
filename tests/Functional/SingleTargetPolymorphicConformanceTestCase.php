<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single-target polymorphic suite, run identically against the in-memory provider
 * ({@see InMemorySingleTargetPolymorphicTest}) and the Doctrine provider
 * ({@see DoctrineSingleTargetPolymorphicTest}).
 *
 * The shared {@see App\Resource\SingleTargetMorphArticleResource} declares `writer`, a
 * `MorphTo` over `authors` alone, and `remarks`, a `MorphToMany` over `comments` alone.
 * Each reads the same column as its monomorphic twin, `author` or `comments`. Being
 * polymorphic, they take none of the related type's filter or sort vocabulary, and their
 * relationship endpoints take no query at all, exactly as a relation over several types.
 * The OpenAPI document projects them as one-member unions and advertises the same.
 */
abstract class SingleTargetPolymorphicConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const array PROFILES = ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . ' ' . CountableProfile::URI . '"'];

    /**
     * Each request, keyed by what it asks, is accepted against the monomorphic twin and
     * refused against the polymorphic relation reading the same column.
     */
    private const array RELATED_TYPE_QUERIES = [
        'to-one related filter' => ['/articles/1/author?filter[name]=x', '/articles/1/writer?filter[name]=x', 'FILTERING_UNRECOGNIZED'],
        'to-one relationship filter' => ['/articles/1/relationships/author?filter[name]=x', '/articles/1/relationships/writer?filter[name]=x', 'FILTERING_UNRECOGNIZED'],
        'to-one relatedQuery filter' => ['/articles/1?relatedQuery[author][filter][name]=x', '/articles/1?relatedQuery[writer][filter][name]=x', 'QUERY_PARAM_UNRECOGNIZED'],
        'to-many related filter' => ['/articles/1/comments?filter[body]=First!', '/articles/1/remarks?filter[body]=First!', 'FILTERING_UNRECOGNIZED'],
        'to-many related sort' => ['/articles/1/comments?sort=body', '/articles/1/remarks?sort=body', 'SORTING_UNSUPPORTED'],
        'to-many relationship filter' => ['/articles/1/relationships/comments?filter[body]=First!', '/articles/1/relationships/remarks?filter[body]=First!', 'FILTERING_UNRECOGNIZED'],
        'to-many relationship sort' => ['/articles/1/relationships/comments?sort=body', '/articles/1/relationships/remarks?sort=body', 'SORTING_UNSUPPORTED'],
        'to-many relationship page' => ['/articles/1/relationships/comments?page[size]=1', '/articles/1/relationships/remarks?page[size]=1', 'QUERY_PARAM_UNRECOGNIZED'],
        'to-many relatedQuery filter' => ['/articles/1?relatedQuery[comments][filter][body]=First!', '/articles/1?relatedQuery[remarks][filter][body]=First!', 'QUERY_PARAM_UNRECOGNIZED'],
        'to-many relatedQuery sort' => ['/articles/1?relatedQuery[comments][sort]=body', '/articles/1?relatedQuery[remarks][sort]=body', 'QUERY_PARAM_UNRECOGNIZED'],
    ];

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aSingleTargetMorphToReadsItsLinkageAndRelatedResource(): void
    {
        self::assertSame(['type' => 'authors', 'id' => '1'], $this->document('/articles/1/relationships/writer')['data'] ?? null);

        $related = $this->document('/articles/1/writer');
        self::assertSame(['authors:1'], $this->members($related, 'data'));
        self::assertSame('Ada Lovelace', $this->at($related, 'data', 'attributes', 'name'));

        $empty = $this->document('/articles/5/writer');
        self::assertArrayHasKey('data', $empty);
        self::assertNull($empty['data']);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aSingleTargetMorphToManyReadsItsLinkageAndRelatedResources(): void
    {
        self::assertSame(
            [['type' => 'comments', 'id' => '1'], ['type' => 'comments', 'id' => '2']],
            $this->document('/articles/1/relationships/remarks')['data'] ?? null,
        );

        self::assertSame(['comments:1', 'comments:2'], $this->members($this->document('/articles/1/remarks'), 'data'));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aSingleTargetPolymorphicRelationIsIncluded(): void
    {
        $included = $this->members($this->document('/articles/1?include=writer,remarks'), 'included');
        \sort($included);
        self::assertSame(['authors:1', 'comments:1', 'comments:2'], $included);
    }

    #[Test]
    #[Group('spec:query-parameters')]
    public function aSingleTargetPolymorphicRelationRefusesTheRelatedTypesQuery(): void
    {
        foreach (self::RELATED_TYPE_QUERIES as $case => [$monomorphic, $polymorphic, $code]) {
            $accepted = $this->handle($monomorphic, extraServer: self::PROFILES);
            self::assertSame(Response::HTTP_OK, $accepted->getStatusCode(), $case . ': ' . $accepted->getContent());

            $refused = $this->handle($polymorphic, extraServer: self::PROFILES);
            self::assertSame(Response::HTTP_BAD_REQUEST, $refused->getStatusCode(), $case . ': ' . $refused->getContent());
            self::assertSame($code, $this->at($this->decode($refused), 'errors', 0, 'code'), $case);
        }
    }

    #[Test]
    #[Group('spec:pagination')]
    public function aSingleTargetMorphToManyRelatedCollectionPagesAndCountsByItsOwnPaginator(): void
    {
        $document = $this->document('/articles/1/remarks?page[size]=1&withCount=_self_');

        self::assertSame(['comments:1'], $this->members($document, 'data'));
        self::assertSame(2, $this->at($document, 'meta', 'total'));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theOpenApiDocumentProjectsASingleTargetPolymorphicRelationAsAUnion(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $document = $factory->forServer()->toArray();

        self::assertSame(GeneratorContract::BASELINE, $this->at($document, 'info', 'x-generator', 'contract'));

        self::assertSame(
            ['anyOf' => [['oneOf' => [['$ref' => '#/components/schemas/AuthorsResourceIdentifier']], 'discriminator' => ['propertyName' => 'type', 'mapping' => ['authors' => '#/components/schemas/AuthorsResourceIdentifier']]], ['type' => 'null']]],
            $this->at($document, 'components', 'schemas', 'ArticlesWriterRelationship', 'properties', 'data'),
        );
        self::assertSame(
            ['type' => 'array', 'items' => ['oneOf' => [['$ref' => '#/components/schemas/CommentsResourceIdentifier']], 'discriminator' => ['propertyName' => 'type', 'mapping' => ['comments' => '#/components/schemas/CommentsResourceIdentifier']]]],
            $this->at($document, 'components', 'schemas', 'ArticlesRemarksRelationship', 'properties', 'data'),
        );

        // The parameters each endpoint advertises are the ones it accepts above.
        self::assertSame(['fields[authors]'], $this->parameterNames($document, '/articles/{id}/writer'));
        self::assertSame(['fields[comments]', 'page', 'withCount'], $this->parameterNames($document, '/articles/{id}/remarks'));
        self::assertSame([], $this->parameterNames($document, '/articles/{id}/relationships/remarks'));
    }

    /**
     * @return array<string, mixed>
     */
    private function document(string $path): array
    {
        $response = $this->handle($path, extraServer: self::PROFILES);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $path . ': ' . $response->getContent());

        return $this->decode($response);
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return list<string>
     */
    private function parameterNames(array $document, string $path): array
    {
        self::assertIsArray($this->at($document, 'paths', $path, 'get'), $path);
        $parameters = $this->at($document, 'paths', $path, 'get', 'parameters') ?? [];
        self::assertIsArray($parameters, $path);

        $names = [];
        foreach ($parameters as $parameter) {
            self::assertIsArray($parameter);
            $name = $parameter['name'] ?? null;
            self::assertIsString($name);
            $names[] = $name;
        }

        return $names;
    }

    /**
     * The value at `$keys` inside a decoded document, `null` when any level is missing.
     */
    private function at(mixed $node, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($node)) {
                return null;
            }
            $node = $node[$key] ?? null;
        }

        return $node;
    }

    /**
     * The `type:id` of each resource object or identifier at `$member`, which holds one
     * or a list.
     *
     * @param array<array-key, mixed> $document
     *
     * @return list<string>
     */
    private function members(array $document, string $member): array
    {
        $data = $document[$member] ?? null;
        self::assertIsArray($data, $member);
        $resources = \array_is_list($data) ? $data : [$data];

        $members = [];
        foreach ($resources as $resource) {
            $type = $this->at($resource, 'type');
            $id = $this->at($resource, 'id');
            self::assertIsString($type);
            self::assertIsString($id);
            $members[] = $type . ':' . $id;
        }

        return $members;
    }
}
