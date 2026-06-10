<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The entity-level validation seam end to end: {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineArticleResource}
 * declares a {@see \haddowg\JsonApiBundle\Validation\Constraint\UniqueEntity} on
 * `title`, which the bridge runs against the hydrated entity (post-hydration,
 * pre-commit) by querying the repository. Doctrine-only: uniqueness has no
 * in-memory analogue.
 */
final class DoctrineUniqueEntityTest extends JsonApiFunctionalTestCase
{
    use SeedsDoctrineArticles;

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingWithADuplicateOfAUniqueFieldReturns422AtThatPointer(): void
    {
        // "JSON:API in PHP" is a seeded title; the UniqueEntity rule rejects the duplicate.
        $response = $this->handle('/articles', 'POST', [
            'data' => ['type' => 'articles', 'attributes' => ['title' => 'JSON:API in PHP', 'category' => 'news']],
        ]);

        self::assertSame(422, $response->getStatusCode());

        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertArrayHasKey(0, $errors);

        $error = $errors[0];
        self::assertIsArray($error);
        self::assertSame('422', $error['status'] ?? null);

        $source = $error['source'] ?? null;
        self::assertIsArray($source);
        self::assertSame('/data/attributes/title', $source['pointer'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingWithAUniqueValuePasses(): void
    {
        $response = $this->handle('/articles', 'POST', [
            'data' => ['type' => 'articles', 'attributes' => ['title' => 'A genuinely unique title', 'category' => 'guide']],
        ]);

        self::assertSame(201, $response->getStatusCode());
    }
}
