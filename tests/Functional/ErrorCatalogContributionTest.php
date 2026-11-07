<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ErrorCatalog\ErrorCatalogTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\ErrorCatalog\Scanned\AccountFrozen;
use haddowg\JsonApiBundle\Tests\Functional\App\ErrorCatalog\Scanned\QuotaExceeded;
use haddowg\JsonApiBundle\Tests\Functional\App\ErrorCatalog\Unscanned\OverdraftRefused;
use haddowg\JsonApiBundle\Tests\Functional\App\ErrorCatalog\Unscanned\SubscriptionRequired;
use haddowg\JsonApiBundle\Tests\Functional\App\ErrorCatalog\Unscanned\TenantSuspended;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The application-contributed error catalogue: an app's own described
 * errors reach the projected document as `const` branches of the error shapes beside
 * core's, and the two ways in are the scan over `json_api.error_codes.paths` and the
 * {@see \haddowg\JsonApiBundle\JsonApiBundle::ERROR_SOURCE_TAG} service tag. A scoped
 * code, scoped by its own descriptor or by a registered
 * {@see \haddowg\JsonApi\Exception\ScopedErrorSource}, is offered only by the
 * operations its scope covers.
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
        $shape = self::errorShapeOf($schemas, QuotaExceeded::describe()->code);

        self::assertNotNull($shape);

        // The shape narrows the shared `Error` with the two members a client dispatches
        // on, so both are readable off `allOf[1]`.
        $narrowing = $this->at($schemas, $shape, 'allOf', 1, 'properties');
        self::assertContainsEquals(
            ['type' => 'string', 'const' => 'QUOTA_EXCEEDED', 'title' => 'Quota exceeded'],
            $this->at($narrowing, 'code', 'anyOf'),
        );
        self::assertSame('429', $this->at($narrowing, 'status')['const'] ?? null);

        // And a client dispatching on the error document sees the branch.
        self::assertContains(
            ['$ref' => '#/components/schemas/' . $shape],
            $this->errorBranches($schemas),
        );
    }

    #[Test]
    #[Group('spec:errors')]
    public function anErrorOutsideTheScannedPathsIsNotCatalogued(): void
    {
        self::assertNull(self::errorShapeOf($this->schemas(), SubscriptionRequired::describe()->code));
    }

    #[Test]
    #[Group('spec:errors')]
    public function anErrorOutsideTheScannedPathsIsCataloguedWhenRegisteredExplicitly(): void
    {
        $schemas = $this->schemas();
        $shape = self::errorShapeOf($schemas, TenantSuspended::describe()->code);

        self::assertNotNull($shape);
        self::assertContains(
            ['$ref' => '#/components/schemas/' . $shape],
            $this->errorBranches($schemas),
        );
    }

    #[Test]
    #[Group('spec:errors')]
    public function coresOwnCodesAreStillCatalogued(): void
    {
        // The contribution joins core's catalogue; it never replaces it.
        self::assertSame('Error404', self::errorShapeOf($this->schemas(), 'RESOURCE_NOT_FOUND'));
    }

    #[Test]
    #[Group('spec:errors')]
    public function aScannedErrorKeepsTheScopeItsDescriptorDeclares(): void
    {
        $document = $this->document();
        $shape = self::errorShapeOf($this->at($document, 'components', 'schemas'), AccountFrozen::describe()->code);

        self::assertNotNull($shape);
        // Only the update the descriptor names offers the code, through a narrowed
        // response; the create's 409 keeps the shared one.
        self::assertSame(['patch /ledgerAccounts/{id}'], $this->operationsOffering($document, $shape));
        self::assertSame(
            '#/components/responses/ConflictWithAccountFrozen',
            $this->at($document, 'paths', '/ledgerAccounts/{id}', 'patch', 'responses', '409')['$ref'] ?? null,
        );
        self::assertSame(
            '#/components/responses/Conflict',
            $this->at($document, 'paths', '/ledgerAccounts', 'post', 'responses', '409')['$ref'] ?? null,
        );
    }

    #[Test]
    #[Group('spec:errors')]
    public function aRegisteredScopedErrorSourceScopesAClassItDoesNotOwn(): void
    {
        // OverdraftRefused's own descriptor declares no scope; the tagged
        // ScopedErrorSource gives it one.
        self::assertNull(OverdraftRefused::describe()->scope);

        $document = $this->document();
        $shape = self::errorShapeOf($this->at($document, 'components', 'schemas'), OverdraftRefused::describe()->code);

        self::assertNotNull($shape);
        // The delete gains the 402 it did not advertise, and nothing else offers it.
        self::assertSame(['delete /ledgerAccounts/{id}'], $this->operationsOffering($document, $shape));
        foreach (['get', 'patch'] as $method) {
            self::assertArrayNotHasKey('402', $this->at($document, 'paths', '/ledgerAccounts/{id}', $method, 'responses'));
        }
    }

    /**
     * The served document.
     *
     * @return array<array-key, mixed>
     */
    private function document(): array
    {
        return $this->decode($this->handle('/docs.json'));
    }

    /**
     * The served document's `components.schemas`.
     *
     * @return array<array-key, mixed>
     */
    private function schemas(): array
    {
        return $this->at($this->document(), 'components', 'schemas');
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
     * Every operation, as `<method> <path>`, one of whose error responses offers `$shape`:
     * the response it `$ref`s carries an error document whose `errors.items.anyOf` lists it.
     *
     * @param array<array-key, mixed> $document
     *
     * @return list<string>
     */
    private function operationsOffering(array $document, string $shape): array
    {
        $offering = [];
        foreach ($this->at($document, 'paths') as $path => $item) {
            self::assertIsArray($item);
            foreach ($item as $method => $operation) {
                if (!\is_array($operation) || !\is_array($operation['responses'] ?? null)) {
                    continue;
                }
                foreach ($operation['responses'] as $response) {
                    $ref = \is_array($response) ? ($response['$ref'] ?? null) : null;
                    if (!\is_string($ref) || !\str_starts_with($ref, '#/components/responses/')) {
                        continue;
                    }
                    $schemaRef = $this->at($document, 'components', 'responses', \substr($ref, \strlen('#/components/responses/')), 'content', 'application/vnd.api+json', 'schema')['$ref'] ?? null;
                    self::assertIsString($schemaRef);
                    $branches = $this->at($document, 'components', 'schemas', \substr($schemaRef, \strlen('#/components/schemas/')), 'properties', 'errors', 'items', 'anyOf');
                    if (\in_array(['$ref' => '#/components/schemas/' . $shape], $branches, true)) {
                        $offering[] = $method . ' ' . $path;
                    }
                }
            }
        }

        return $offering;
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
