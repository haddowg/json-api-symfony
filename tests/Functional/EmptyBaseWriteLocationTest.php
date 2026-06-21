<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\EmptyBaseWritableInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Regression for the create `Location` under an **empty** `base_uri`: with no
 * configured base, every link in the body is resolved from the request origin
 * (`<scheme>://<authority>`), and the `Location` header must follow the same
 * resolution so it stays equal to the created resource's `data.links.self`
 * (core ADR 0054). The dual-provider {@see WriteConformanceTestCase} kernels all
 * pin a non-empty base, so they only exercise the configured-base path; this case
 * covers the request-origin path the empty base introduces.
 */
final class EmptyBaseWriteLocationTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return EmptyBaseWritableInMemoryTestKernel::class;
    }

    #[Test]
    #[Group('spec:crud')]
    public function the201LocationMatchesTheRequestAbsoluteSelfLinkUnderAnEmptyBase(): void
    {
        $response = $this->handle('/articles', 'POST', [
            'data' => [
                'type' => 'articles',
                'attributes' => [
                    'title' => 'A brand new article',
                    'body' => 'Fresh content.',
                    'category' => 'news',
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        // The store assigns the next id past the five seeded rows.
        $id = $data['id'] ?? null;
        self::assertSame('6', $id);

        // With an empty base_uri the request origin (`Request::create()` defaults to
        // `http://localhost`) is the link prefix, so both the body self and the
        // Location are request-host-absolute — and equal.
        $expected = 'http://localhost/articles/' . $id;

        $links = $data['links'] ?? null;
        self::assertIsArray($links);
        self::assertSame($expected, $links['self'] ?? null);

        self::assertSame($expected, $response->headers->get('Location'));
        self::assertSame($links['self'], $response->headers->get('Location'));
    }
}
