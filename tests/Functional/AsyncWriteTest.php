<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Async\AsyncInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The async-write seam witness (bundle ADR 0110): a persister that accepts writes for
 * asynchronous processing ({@see \haddowg\JsonApiBundle\Tests\Functional\App\Async\AsyncArticlesPersister})
 * makes `POST`/`PATCH` render a `202 Accepted` with `Content-Location` + `Retry-After`
 * pointing at a pollable job resource, and a completion action drives the `303 See
 * Other` leg — the full JSON:API asynchronous-processing lifecycle end-to-end over HTTP.
 */
final class AsyncWriteTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return AsyncInMemoryTestKernel::class;
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingAResourceIsAcceptedForAsynchronousProcessing(): void
    {
        $response = $this->handle('/articles', 'POST', [
            'data' => [
                'type' => 'articles',
                'attributes' => [
                    'title' => 'Queued for later',
                    'body' => 'Processed off the request.',
                    'category' => 'news',
                ],
            ],
        ]);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('https://example.test/jobs/job-1', $response->headers->get('Content-Location'));
        self::assertSame('30', $response->headers->get('Retry-After'));

        // The 202 body is the pollable job resource, rendered through the jobs serializer.
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('jobs', $data['type'] ?? null);
        self::assertSame('job-1', $data['id'] ?? null);
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('queued', $attributes['status'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function updatingAResourceIsAcceptedForAsynchronousProcessing(): void
    {
        $response = $this->handle('/articles/1', 'PATCH', [
            'data' => [
                'type' => 'articles',
                'id' => '1',
                'attributes' => ['title' => 'An async edit'],
            ],
        ]);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('https://example.test/jobs/job-2', $response->headers->get('Content-Location'));
        self::assertSame('30', $response->headers->get('Retry-After'));

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('jobs', $data['type'] ?? null);
        self::assertSame('job-2', $data['id'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function aCompletionActionRedirectsWithSeeOther(): void
    {
        $response = $this->handle('/jobs/-actions/complete', 'POST');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://example.test/articles/1', $response->headers->get('Location'));
        self::assertSame('', $response->getContent());
    }
}
