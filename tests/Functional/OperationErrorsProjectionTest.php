<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\OperationErrors\OperationErrorsTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A code declared through `#[AsJsonApiResource(operationErrors:)]` or
 * `#[AsJsonApiAction(errors:)]` reaches the served document on the declared operation's
 * error response alone.
 */
final class OperationErrorsProjectionTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return OperationErrorsTestKernel::class;
    }

    #[Test]
    #[Group('spec:errors')]
    public function aCodeDeclaredForTheUpdateIsOfferedByTheUpdateAlone(): void
    {
        $document = $this->document();

        self::assertSame('#/components/responses/ConflictWithAccountLocked', $this->at($document, 'paths', '/accounts/{id}', 'patch', 'responses', '409', '$ref'));
        self::assertSame('#/components/responses/Conflict', $this->at($document, 'paths', '/accounts', 'post', 'responses', '409', '$ref'));
        self::assertContains(
            ['$ref' => '#/components/schemas/Error409AccountLocked'],
            $this->arr($document, 'components', 'schemas', 'ErrorDocument409WithAccountLocked', 'properties', 'errors', 'items', 'anyOf'),
        );
    }

    #[Test]
    #[Group('spec:errors')]
    public function aCodeDeclaredOnAnActionIsOfferedByThatActionAlone(): void
    {
        $document = $this->document();

        self::assertSame('#/components/responses/Status402WithOverdraftRefused', $this->at($document, 'paths', '/accounts/{id}/-actions/freeze', 'post', 'responses', '402', '$ref'));
        self::assertArrayNotHasKey('402', $this->arr($document, 'paths', '/accounts/{id}', 'patch', 'responses'));
        self::assertArrayNotHasKey('402', $this->arr($document, 'paths', '/accounts', 'post', 'responses'));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function document(): array
    {
        return $this->decode($this->handle('/docs.json'));
    }

    /**
     * Walks `$keys` through nested arrays, asserting each hop exists and is an array.
     *
     * @param array<array-key, mixed> $document
     */
    private function at(array $document, string ...$keys): mixed
    {
        $cursor = $document;
        foreach ($keys as $key) {
            self::assertIsArray($cursor);
            self::assertArrayHasKey($key, $cursor);
            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    private function arr(array $document, string ...$keys): array
    {
        $found = $this->at($document, ...$keys);
        self::assertIsArray($found);

        return $found;
    }
}
