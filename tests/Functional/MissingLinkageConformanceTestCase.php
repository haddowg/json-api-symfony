<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every relationship write refuses linkage to a resource that does not exist with a
 * `404`, before anything is stored, whichever persister applies it: a create, an update
 * and each relationship endpoint, to-one and to-many. A remove naming a missing resource
 * is refused the same way, and one naming an existing resource that is not a member
 * changes nothing. Article 1 is seeded with author 1 and comments 1 and 2, article 2
 * with comment 3.
 */
abstract class MissingLinkageConformanceTestCase extends JsonApiFunctionalTestCase
{
    /**
     * Detaches persisted state before a re-read, so it reads the store.
     */
    protected function detachPersistedState(): void {}

    #[Test]
    #[Group('spec:creating-resources')]
    public function aCreateNamingAMissingResourceIsNotFoundAndStoresNothing(): void
    {
        $before = $this->articleCount();
        $relationships = [
            ['author' => ['data' => ['type' => 'authors', 'id' => '999']]],
            ['comments' => ['data' => [['type' => 'comments', 'id' => '1'], ['type' => 'comments', 'id' => '999']]]],
        ];
        foreach ($relationships as $relationship) {
            $this->assertNotFound($this->handle('/articles', 'POST', ['data' => [
                'type' => 'articles',
                'attributes' => ['title' => 'Orphan', 'body' => 'x', 'category' => 'news'],
                'relationships' => $relationship,
            ]]));
        }

        self::assertSame($before, $this->articleCount());
        self::assertSame(['1', '2'], $this->commentIds('/articles/1/relationships/comments'));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function anUpdateNamingAMissingResourceIsNotFoundAndChangesNothing(): void
    {
        $relationships = [
            ['author' => ['data' => ['type' => 'authors', 'id' => '999']]],
            ['comments' => ['data' => [['type' => 'comments', 'id' => '999']]]],
        ];
        foreach ($relationships as $relationship) {
            $this->assertNotFound($this->handle('/articles/1', 'PATCH', ['data' => [
                'type' => 'articles',
                'id' => '1',
                'attributes' => ['title' => 'Renamed'],
                'relationships' => $relationship,
            ]]));
        }

        $this->assertArticleOneUnchanged();
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function theRelationshipEndpointsRefuseAMissingResourceOnEveryMode(): void
    {
        $this->assertNotFound($this->handle('/articles/1/relationships/author', 'PATCH', ['data' => ['type' => 'authors', 'id' => '999']]));
        foreach (['PATCH', 'POST', 'DELETE'] as $method) {
            $this->assertNotFound($this->handle('/articles/1/relationships/comments', $method, ['data' => [['type' => 'comments', 'id' => '2'], ['type' => 'comments', 'id' => '999']]]));
        }

        $this->assertArticleOneUnchanged();
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function removingAnExistingResourceThatIsNotAMemberChangesNothing(): void
    {
        $response = $this->handle('/articles/1/relationships/comments', 'DELETE', ['data' => [['type' => 'comments', 'id' => '3']]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(['1', '2'], $this->commentIds('/articles/1/relationships/comments'));
        self::assertSame(['3'], $this->commentIds('/articles/2/relationships/comments'));
    }

    private function assertArticleOneUnchanged(): void
    {
        $this->detachPersistedState();
        $article = $this->decode($this->handle('/articles/1'))['data'] ?? null;
        self::assertIsArray($article);
        self::assertSame('JSON:API in PHP', \is_array($article['attributes'] ?? null) ? ($article['attributes']['title'] ?? null) : null);
        $this->detachPersistedState();
        $author = $this->decode($this->handle('/articles/1/relationships/author'))['data'] ?? null;
        self::assertSame(['type' => 'authors', 'id' => '1'], $author);
        self::assertSame(['1', '2'], $this->commentIds('/articles/1/relationships/comments'));
    }

    private function assertNotFound(Response $response): void
    {
        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertSame('RESOURCE_NOT_FOUND', \is_array($errors[0] ?? null) ? ($errors[0]['code'] ?? null) : null);
    }

    private function articleCount(): int
    {
        $this->detachPersistedState();
        $data = $this->decode($this->handle('/articles?page[size]=100'))['data'] ?? null;
        self::assertIsArray($data);

        return \count($data);
    }

    /**
     * @return list<string>
     */
    private function commentIds(string $path): array
    {
        $this->detachPersistedState();
        $data = $this->decode($this->handle($path))['data'] ?? null;
        self::assertIsArray($data);
        $ids = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            self::assertIsString($identifier['id'] ?? null);
            $ids[] = $identifier['id'];
        }
        \sort($ids);

        return $ids;
    }
}
