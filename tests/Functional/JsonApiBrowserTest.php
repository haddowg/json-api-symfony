<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\JsonApiTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Focused coverage of the shipped {@see \haddowg\JsonApiBundle\Testing\JsonApiBrowser}
 * itself, over the read kernel: a GET fluent chain, the `?sort` order witness
 * ({@see \haddowg\JsonApi\Testing\JsonApiDocument::assertFetchedManyInOrder()}, the
 * only clean way to assert order), an exact-match read derived from the entity's own
 * serializer via {@see \haddowg\JsonApiBundle\Testing\JsonApiBrowser::expectResource()},
 * and a 404 still rendering as a JSON:API **error document** through the browser
 * (proving the kernel.exception path survives the KernelBrowser routing).
 *
 * The write-side traits (auto Content-Type, JSON body, `assertCreated`, and the
 * disableReboot write-then-read) are covered by {@see JsonApiBrowserWriteTest}.
 */
final class JsonApiBrowserTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return JsonApiTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-resources')]
    public function a_get_chain_asserts_status_content_type_and_body_as_a_unit(): void
    {
        $this->browser()
            ->get('/articles/1')
            ->assertFetchedOne()
            ->assertHasType('articles')
            ->assertHasId('1')
            ->assertHasAttribute('title', 'JSON:API in PHP');
    }

    #[Test]
    #[Group('spec:fetching-resources')]
    public function the_collection_is_returned_as_a_list_of_resource_objects(): void
    {
        $this->browser()
            ->get('/articles')
            ->assertFetchedMany()
            ->assertCollectionCount(5)
            ->assertCollectionContains('articles', '3');
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function sort_order_is_witnessed_in_exact_order(): void
    {
        // Titles ascending: Async(5), Building(3), JSON:API(1), Second(2), Zebra(4).
        $this->browser()
            ->get('/articles?sort=title')
            ->assertFetchedManyInOrder(['5', '3', '1', '2', '4'], 'articles');
    }

    #[Test]
    #[Group('spec:fetching-resources')]
    public function exact_match_catches_no_leaked_fields(): void
    {
        $article = $this->seededArticle('1');

        // The expected resource object is derived from the entity's own serializer,
        // so the assertion is the strongest possible: the rendered member must equal
        // it exactly — any leaked or extra field fails.
        $this->browser()
            ->get('/articles/1')
            ->assertFetchedOneExact($this->browser()->expectResource($article));
    }

    #[Test]
    public function acting_as_a_string_sets_a_stateless_bearer_token(): void
    {
        $browser = $this->browser()->actingAs('ada@example.com');

        self::assertSame('Bearer ada@example.com', $browser->getServerParameter('HTTP_AUTHORIZATION'));
    }

    #[Test]
    public function acting_as_a_user_resolves_its_identifier_for_the_bearer_token(): void
    {
        $user = new InMemoryUser('ada@example.com', null, ['ROLE_USER']);

        $browser = $this->browser()->actingAs($user);

        self::assertSame('Bearer ada@example.com', $browser->getServerParameter('HTTP_AUTHORIZATION'));
    }

    #[Test]
    #[Group('spec:fetching-resources')]
    public function a_missing_resource_renders_an_error_document_through_the_browser(): void
    {
        $errors = $this->browser()->get('/articles/999')->getErrors();

        $errors->assertStatus(404)
            ->assertContentType()
            ->assertHasError(status: '404');
    }

    private function seededArticle(string $id): object
    {
        $provider = static::getContainer()->get('test.articles_provider');
        \assert($provider instanceof InMemoryDataProvider);
        $article = $provider->fetchOne('articles', $id);
        self::assertNotNull($article);

        return $article;
    }
}
