<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The write-only relation suite, run identically against the in-memory provider
 * ({@see InMemoryWriteOnlyRelationTest}) and the Doctrine provider
 * ({@see DoctrineWriteOnlyRelationTest}).
 *
 * The shared {@see App\Resource\WriteOnlyRelationArticleResource} declares
 * `secretComments`, a `withData()` to-many marked `writeOnly()`. Reads never render
 * it, so a `relatedQuery` addressing it is an unknown path, the OpenAPI document
 * describes no way to read it, and its relationship endpoint still takes writes.
 */
abstract class WriteOnlyRelationConformanceTestCase extends JsonApiFunctionalTestCase
{
    protected const string PROFILE_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"';

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function aReadDocumentLeavesTheWriteOnlyRelationOut(): void
    {
        $response = $this->handle('/articles/1', extraServer: ['HTTP_ACCEPT' => self::PROFILE_ACCEPT]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);
        self::assertArrayHasKey('comments', $relationships);
        self::assertArrayNotHasKey('secretComments', $relationships);
    }

    #[Test]
    #[Group('profile:relationship-queries')]
    public function aRelatedQueryAddressingAWriteOnlyRelationIsRejected(): void
    {
        $response = $this->handle(
            '/articles?relatedQuery[secretComments][filter][body]=First',
            extraServer: ['HTTP_ACCEPT' => self::PROFILE_ACCEPT],
        );
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), (string) $response->getContent());

        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        $error = $errors[0] ?? null;
        self::assertIsArray($error);
        self::assertSame('QUERY_PARAM_UNRECOGNIZED', $error['code'] ?? null);
        self::assertSame(['parameter' => 'relatedQuery[secretComments]'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('profile:relationship-queries')]
    public function aRelatedQueryOnAReadableSiblingStillWorks(): void
    {
        $response = $this->handle(
            '/articles/1?relatedQuery[comments][filter][body]=First',
            extraServer: ['HTTP_ACCEPT' => self::PROFILE_ACCEPT],
        );
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theOpenApiDocumentDescribesNoReadOfTheWriteOnlyRelation(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $document = $factory->forServer()->toArray();

        $paths = $document['paths'] ?? null;
        self::assertIsArray($paths);
        self::assertArrayNotHasKey('/articles/{id}/secretComments', $paths);
        self::assertArrayHasKey('/articles/{id}/comments', $paths);

        $relationship = $paths['/articles/{id}/relationships/secretComments'] ?? null;
        self::assertIsArray($relationship, 'the relationship mutations are still documented');
        self::assertArrayNotHasKey('get', $relationship);
        self::assertArrayHasKey('patch', $relationship);

        $readable = $paths['/articles/{id}/relationships/comments'] ?? null;
        self::assertIsArray($readable);
        self::assertArrayHasKey('get', $readable);
    }

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    public function theWriteOnlyRelationshipStillAcceptsAReplacement(): void
    {
        $response = $this->handle('/articles/1/relationships/secretComments', 'PATCH', [
            'data' => [['type' => 'comments', 'id' => '1']],
        ]);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());
    }
}
