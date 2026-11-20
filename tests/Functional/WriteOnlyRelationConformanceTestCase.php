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
 * `lockedSecretComments`, over the same column, can't change its members: a replace
 * compares the comments the article holds, and a `PATCH` action taking it as input
 * compares it with the blank instance it hydrates.
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

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    #[Group('spec:fetching-includes')]
    public function aWriteOnlyRelationshipMutationIgnoresInclude(): void
    {
        // The mutation renders nothing, so like a delete it never reads include.
        $response = $this->handle('/articles/1/relationships/secretComments?include=nonsense', 'PATCH', [
            'data' => [['type' => 'comments', 'id' => '1']],
        ]);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    public function aPatchKeepingTheMembersOfALockedWriteOnlyToManyIsAccepted(): void
    {
        $this->pinComments(['1', '2']);

        $response = $this->handle('/articles/1', 'PATCH', ['data' => [
            'type' => 'articles',
            'id' => '1',
            'relationships' => ['lockedSecretComments' => ['data' => [['type' => 'comments', 'id' => '2'], ['type' => 'comments', 'id' => '1']]]],
        ]]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $relationships = $data['relationships'] ?? [];
        self::assertIsArray($relationships);
        self::assertArrayNotHasKey('lockedSecretComments', $relationships);
    }

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    public function aPatchChangingTheMembersOfALockedWriteOnlyToManyIsForbidden(): void
    {
        // The article holds comments 1 and 2, so naming only 1 changes its members.
        $this->pinComments(['1', '2']);

        $response = $this->handle('/articles/1', 'PATCH', ['data' => [
            'type' => 'articles',
            'id' => '1',
            'relationships' => ['lockedSecretComments' => ['data' => [['type' => 'comments', 'id' => '1']]]],
        ]]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:actions')]
    public function aPatchActionComparesALockedWriteOnlyToManyWithItsBlankInput(): void
    {
        // A PATCH action hydrates its input as an update onto a blank instance, so naming
        // the very comments the article holds still changes the members it compares with.
        $this->pinComments(['1', '2']);

        $response = $this->revise(['1', '2']);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode(), (string) $response->getContent());
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        $error = $errors[0] ?? null;
        self::assertIsArray($error);
        self::assertSame('MEMBERSHIP_CHANGE_PROHIBITED', $error['code'] ?? null);
    }

    /**
     * @param list<string> $ids
     */
    private function revise(array $ids): Response
    {
        return $this->handle('/articles/1/-actions/revise', 'PATCH', ['data' => [
            'type' => 'articles',
            'id' => '1',
            'relationships' => ['lockedSecretComments' => ['data' => \array_map(static fn(string $id): array => ['type' => 'comments', 'id' => $id], $ids)]],
        ]]);
    }

    /**
     * @param list<string> $ids
     */
    private function pinComments(array $ids): void
    {
        $response = $this->handle('/articles/1/relationships/secretComments', 'PATCH', [
            'data' => \array_map(static fn(string $id): array => ['type' => 'comments', 'id' => $id], $ids),
        ]);
        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());
    }
}
