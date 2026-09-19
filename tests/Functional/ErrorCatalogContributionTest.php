<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\OpenApi\ErrorCatalogProjector;
use haddowg\JsonApiBundle\Tests\Functional\App\ErrorCatalog\ErrorCatalogTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\ErrorCatalog\Scanned\QuotaExceeded;
use haddowg\JsonApiBundle\Tests\Functional\App\ErrorCatalog\Unscanned\SubscriptionRequired;
use haddowg\JsonApiBundle\Tests\Functional\App\ErrorCatalog\Unscanned\TenantSuspended;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The application-contributed error catalogue (core ADR 0136): an app's own described
 * errors reach the projected document as named schema variants beside core's, and the
 * two ways in are the scan over `json_api.error_codes.paths` and the
 * {@see \haddowg\JsonApiBundle\JsonApiBundle::ERROR_SOURCE_TAG} service tag.
 *
 * The load-bearing test is {@see anErrorOutsideTheScannedPathsIsNotCatalogued}. Discovery
 * that reached beyond its configured paths would publish whatever described error happened
 * to be lying around — a fixture, a scratch class — as part of the API's contract, so the
 * scoping is the feature, not an implementation detail of it.
 */
final class ErrorCatalogContributionTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return ErrorCatalogTestKernel::class;
    }

    #[Test]
    #[Group('spec:errors')]
    public function anErrorUnderAScannedPathIsCatalogued(): void
    {
        $schemas = $this->schemas();
        $component = ErrorCatalogProjector::componentName(QuotaExceeded::describe()->code);

        self::assertArrayHasKey($component, $schemas);

        // The variant narrows the shared `Error` with the two members a client dispatches
        // on, so both are readable off `allOf[1]`.
        $narrowing = $this->at($schemas, $component, 'allOf', 1, 'properties');
        self::assertSame('QUOTA_EXCEEDED', $this->at($narrowing, 'code')['const'] ?? null);
        self::assertSame('429', $this->at($narrowing, 'status')['const'] ?? null);

        // And a client dispatching on the error document sees the branch.
        self::assertContains(
            ['$ref' => '#/components/schemas/' . $component],
            $this->errorBranches($schemas),
        );
    }

    #[Test]
    #[Group('spec:errors')]
    public function anErrorOutsideTheScannedPathsIsNotCatalogued(): void
    {
        $schemas = $this->schemas();

        self::assertArrayNotHasKey(
            ErrorCatalogProjector::componentName(SubscriptionRequired::describe()->code),
            $schemas,
        );
    }

    #[Test]
    #[Group('spec:errors')]
    public function anErrorOutsideTheScannedPathsIsCataloguedWhenRegisteredExplicitly(): void
    {
        $schemas = $this->schemas();
        $component = ErrorCatalogProjector::componentName(TenantSuspended::describe()->code);

        self::assertArrayHasKey($component, $schemas);
        self::assertContains(
            ['$ref' => '#/components/schemas/' . $component],
            $this->errorBranches($schemas),
        );
    }

    #[Test]
    #[Group('spec:errors')]
    public function coresOwnCodesAreStillCatalogued(): void
    {
        // The contribution joins core's catalogue; it never replaces it.
        self::assertArrayHasKey('ResourceNotFoundError', $this->schemas());
    }

    /**
     * The served document's `components.schemas`.
     *
     * @return array<array-key, mixed>
     */
    private function schemas(): array
    {
        return $this->at($this->decode($this->handle('/docs.json')), 'components', 'schemas');
    }

    /**
     * The `anyOf` branches `ErrorDocument.errors.items` offers.
     *
     * @param array<array-key, mixed> $schemas
     *
     * @return list<mixed>
     */
    private function errorBranches(array $schemas): array
    {
        return \array_values($this->at($schemas, 'ErrorDocument', 'properties', 'errors', 'items', 'anyOf'));
    }

    /**
     * Walks `$keys` through nested arrays, asserting each hop exists and is itself an
     * array — so the assertion that matters fails on the missing key rather than on a
     * type error three levels down.
     *
     * @param array<array-key, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    private function at(array $document, string|int ...$keys): array
    {
        $cursor = $document;
        foreach ($keys as $key) {
            self::assertArrayHasKey($key, $cursor);
            $next = $cursor[$key];
            self::assertIsArray($next);
            $cursor = $next;
        }

        return $cursor;
    }
}
