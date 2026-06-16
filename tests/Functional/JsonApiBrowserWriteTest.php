<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\WritableInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The write-side coverage of {@see \haddowg\JsonApiBundle\Testing\JsonApiBrowser}:
 * the auto write media type + JSON-encoded body, `assertCreated` (201 + Location +
 * content type), the **disableReboot** guarantee (a write-then-read in one test sees
 * the write — the booted kernel and its in-memory store survive across requests),
 * and a `422` validation failure still rendering as a JSON:API error document
 * through the browser.
 */
final class JsonApiBrowserWriteTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return WritableInMemoryTestKernel::class;
    }

    #[Test]
    #[Group('spec:crud')]
    public function a_post_auto_encodes_the_body_and_asserts_created(): void
    {
        $this->browser()
            ->post('/articles', [
                'data' => [
                    'type' => 'articles',
                    'attributes' => [
                        'title' => 'A brand new article',
                        'body' => 'Fresh content.',
                        'category' => 'news',
                    ],
                ],
            ])
            ->assertCreated()
            ->assertHeader('Location', 'https://example.test/articles/6');
    }

    #[Test]
    #[Group('spec:crud')]
    public function a_write_then_read_in_one_test_sees_the_write(): void
    {
        $browser = $this->browser();

        // The create and the follow-up read share the one booted kernel (reboot
        // disabled), so the in-memory store the write touched is the same one the
        // read queries — the seed and the new row both survive.
        $browser->post('/articles', [
            'data' => [
                'type' => 'articles',
                'attributes' => ['title' => 'Persisted via browser', 'body' => 'x', 'category' => 'guide'],
            ],
        ])->assertCreated();

        $browser->get('/articles/6')
            ->assertFetchedOne()
            ->assertHasId('6')
            ->assertHasAttribute('title', 'Persisted via browser');
    }

    #[Test]
    #[Group('spec:crud')]
    public function a_validation_failure_renders_a_422_error_document_through_the_browser(): void
    {
        $errors = $this->browser()->post('/articles', [
            'data' => [
                'type' => 'articles',
                // `title` is required with a 3-char minimum; "ab" is too short.
                'attributes' => ['title' => 'ab', 'body' => 'x', 'category' => 'guide'],
            ],
        ])->getErrors();

        $errors->assertStatus(422)
            ->assertContentType()
            ->assertHasError(status: '422', pointer: '/data/attributes/title');
    }
}
