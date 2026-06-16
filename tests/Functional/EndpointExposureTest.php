<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\EndpointExposureTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The endpoint-exposure witness (ADR 0027): relationship routes stay parametric,
 * so the {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler} enforces each
 * relation's endpoint-exposure flags. A `withoutRelatedEndpoint()` /
 * `withoutRelationshipEndpoint()` relation's read is a `404` (reusing
 * `RelationshipNotExists`), a `cannotAdd()` to-many `POST` is a `403`
 * (`AdditionProhibited`), and the convention links omit the link to a suppressed
 * endpoint so a rendered self/related link never points at a `404`. Storage-
 * orthogonal, so witnessed on the in-memory kernel only.
 */
final class EndpointExposureTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return EndpointExposureTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aSuppressedRelatedEndpointIsA404WhileItsRelationshipEndpointStays(): void
    {
        // secretAuthor suppresses its related endpoint but keeps its relationship one.
        self::assertSame(404, $this->handle('/gizmos/g1/secretAuthor')->getStatusCode());
        self::assertSame(200, $this->handle('/gizmos/g1/relationships/secretAuthor')->getStatusCode());
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aSuppressedRelationshipEndpointIsA404WhileItsRelatedEndpointStays(): void
    {
        // hiddenAuthor suppresses its relationship endpoint but keeps its related one.
        self::assertSame(200, $this->handle('/gizmos/g1/hiddenAuthor')->getStatusCode());
        self::assertSame(404, $this->handle('/gizmos/g1/relationships/hiddenAuthor')->getStatusCode());
    }

    #[Test]
    #[Group('spec:crud-updating-to-many-relationships')]
    public function aPostAddToACannotAddToManyIsForbidden(): void
    {
        $response = $this->handle('/gizmos/g1/relationships/lockedComments', 'POST', [
            'data' => [['type' => 'comments', 'id' => '1']],
        ]);

        self::assertSame(403, $response->getStatusCode());

        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        $first = $errors[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('ADDITION_PROHIBITED', $first['code'] ?? null);
    }

    #[Test]
    #[Group('spec:crud-updating-to-many-relationships')]
    public function aNormalToManyIsNotGatedByTheAdditionProhibition(): void
    {
        // The comments control allows add: its relationship endpoint is routed and
        // reachable (200), and a POST to it does NOT trip the AdditionProhibited
        // gate (403) the locked relation does. (This kernel wires no persister — a
        // POST past the gate reaches the persister registry and fails there, NOT at
        // the addition gate — so the control is asserted on the read endpoint, the
        // direct witness that `comments` is exposed and not the locked relation.)
        self::assertSame(200, $this->handle('/gizmos/g1/relationships/comments')->getStatusCode());
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function conventionLinksOmitTheLinkToASuppressedEndpoint(): void
    {
        $response = $this->handle('/gizmos/g1');
        self::assertSame(200, $response->getStatusCode());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);

        // secretAuthor: self (relationship endpoint) but NOT related.
        $secretAuthor = $relationships['secretAuthor'] ?? null;
        self::assertIsArray($secretAuthor);
        $secretLinks = $secretAuthor['links'] ?? null;
        self::assertIsArray($secretLinks);
        self::assertArrayHasKey('self', $secretLinks);
        self::assertArrayNotHasKey('related', $secretLinks);

        // hiddenAuthor: related but NOT self (relationship endpoint).
        $hiddenAuthor = $relationships['hiddenAuthor'] ?? null;
        self::assertIsArray($hiddenAuthor);
        $hiddenLinks = $hiddenAuthor['links'] ?? null;
        self::assertIsArray($hiddenLinks);
        self::assertArrayHasKey('related', $hiddenLinks);
        self::assertArrayNotHasKey('self', $hiddenLinks);

        // author control: BOTH self and related.
        $author = $relationships['author'] ?? null;
        self::assertIsArray($author);
        $authorLinks = $author['links'] ?? null;
        self::assertIsArray($authorLinks);
        self::assertArrayHasKey('self', $authorLinks);
        self::assertArrayHasKey('related', $authorLinks);
    }

    #[Test]
    #[Group('spec:document-resource-objects')]
    public function aResourceOptedOutOfTheSelfLinkOmitsItsResourceSelfButKeepsTheDocumentSelf(): void
    {
        // GizmoResource::emitsSelfLink() returns false (core ADR 0054), so the
        // resource object carries no convention `data.links.self`. The opt-out is
        // resource-scoped: the top-level document `self` (the request URI) is
        // unaffected and still present.
        $document = $this->decode($this->handle('/gizmos/g1'));

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $dataLinks = $data['links'] ?? null;
        if (\is_array($dataLinks)) {
            self::assertArrayNotHasKey('self', $dataLinks);
        }

        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertSame('https://example.test/gizmos/g1', $links['self'] ?? null);
    }
}
