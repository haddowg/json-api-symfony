<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The acceptance suite for the parent-aware identifier-meta hook (core ADR 0084),
 * run identically against the in-memory provider ({@see InMemoryIdentifierMetaTest})
 * and the Doctrine provider ({@see DoctrineIdentifierMetaTest}).
 *
 * The shared {@see App\Resource\IdentifierMetaArticleResource} declares an
 * `identifierMeta()` on the to-one `author` and the to-many `comments`. Each
 * resolver reads BOTH the owning article and the related object, so it stamps each
 * linkage identifier with `fromArticle` — the *parent's* id carried on the *related*
 * identifier, which the related resource's own `getMeta()` could never produce.
 *
 *  - a to-one relationship endpoint emits the resolver's meta on its single
 *    identifier;
 *  - a to-many relationship endpoint emits it per member, resolved against each
 *    member;
 *  - a compound document carries the meta on the linkage identifiers while the
 *    related resource object expanded into `included` is untouched (linkage-only).
 *
 * The article fixtures seed article 1 with author 1 (Ada Lovelace) and comments 1
 * ("First!") and 2 ("Nice write-up.").
 */
abstract class IdentifierMetaConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    #[Group('spec:document-resource-identifier-objects')]
    public function aToOneRelationshipEndpointCarriesTheParentAwareIdentifierMeta(): void
    {
        $document = $this->fetchDocument('/articles/1/relationships/author');

        self::assertSame(
            ['type' => 'authors', 'id' => '1', 'meta' => ['fromArticle' => 1, 'authorName' => 'Ada Lovelace']],
            $document['data'] ?? null,
        );
    }

    #[Test]
    #[Group('spec:document-resource-identifier-objects')]
    public function aToManyRelationshipEndpointCarriesPerMemberIdentifierMeta(): void
    {
        $document = $this->fetchDocument('/articles/1/relationships/comments');

        self::assertSame(
            [
                ['type' => 'comments', 'id' => '1', 'meta' => ['fromArticle' => 1, 'commentId' => 1]],
                ['type' => 'comments', 'id' => '2', 'meta' => ['fromArticle' => 1, 'commentId' => 2]],
            ],
            $document['data'] ?? null,
        );
    }

    #[Test]
    #[Group('spec:document-resource-identifier-objects')]
    public function theIdentifierMetaIsLinkageOnlyAndDoesNotTouchTheIncludedResource(): void
    {
        $document = $this->fetchDocument('/articles/1?include=comments');

        // The linkage identifiers inside the compound document carry the meta.
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);
        $comments = $relationships['comments'] ?? null;
        self::assertIsArray($comments);
        self::assertSame(
            [
                ['type' => 'comments', 'id' => '1', 'meta' => ['fromArticle' => 1, 'commentId' => 1]],
                ['type' => 'comments', 'id' => '2', 'meta' => ['fromArticle' => 1, 'commentId' => 2]],
            ],
            $comments['data'] ?? null,
        );

        // The related resource objects expanded into `included` are untouched: the
        // identifier meta rides the linkage, never the resource. The comment type
        // emits no meta of its own, so the included resources carry no `meta` member.
        $included = $document['included'] ?? [];
        self::assertIsArray($included);
        self::assertNotSame([], $included, 'the comments are expanded into included');
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            self::assertSame('comments', $resource['type'] ?? null);
            self::assertArrayNotHasKey('meta', $resource);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDocument(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }
}
