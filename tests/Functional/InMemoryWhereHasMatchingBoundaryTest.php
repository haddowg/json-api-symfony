<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ConstrainedFilterInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The in-memory boundary witness for the Doctrine-only
 * {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\Filter\WhereHasMatching} escape
 * hatch (bundle ADR 0069). The hatch is declared only on the Doctrine resource, so
 * on the in-memory kernel its `filter[<key>]` keys are **undeclared** — and an
 * undeclared filter key is the deliberate unrecognised-filter `400`
 * ({@see \haddowg\JsonApi\Exception\FilterParamUnrecognized}, `source.parameter`),
 * exactly like the pivot-filter prefix. This proves the boundary is a clean client
 * error, never a silent non-match — the Doctrine acceptance lives in
 * {@see DoctrineWhereHasMatchingTest}.
 */
final class InMemoryWhereHasMatchingBoundaryTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return ConstrainedFilterInMemoryTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function theCriteriaFilterKeyIsUnrecognisedOnTheInMemoryProvider(): void
    {
        $response = $this->handle('/articles?filter[editorNamed]=ignored');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $error = $this->firstError($this->decode($response));
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame(['parameter' => 'filter[editorNamed]'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function theClosureFilterKeyIsUnrecognisedOnTheInMemoryProvider(): void
    {
        $response = $this->handle('/articles?filter[editorNameLike]=Grace');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());

        $error = $this->firstError($this->decode($response));
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame(['parameter' => 'filter[editorNameLike]'], $error['source'] ?? null);
    }

    /**
     * The document's first error object.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function firstError(array $document): array
    {
        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $first = $errors[0] ?? null;
        self::assertIsArray($first);

        /** @var array<string, mixed> $first */
        return $first;
    }
}
