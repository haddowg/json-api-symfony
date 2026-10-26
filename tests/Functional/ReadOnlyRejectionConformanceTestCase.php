<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * `json_api.servers.strict.reject_read_only_attributes`: the `strict` server answers a
 * write that sends an attribute the client cannot write with a 403 naming each one, and
 * before validation, while the default server keeps ignoring such attributes. Memo 1 is
 * seeded.
 */
abstract class ReadOnlyRejectionConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    public function theDefaultServerIgnoresAReadOnlyAttribute(): void
    {
        $response = $this->create('', ['body' => 'Hello', 'author' => 'mallory']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('system', $this->member($this->decode($response), 'data', 'attributes', 'author'));
    }

    #[Test]
    public function theStrictServerRejectsEveryReadOnlyAttributeOnCreate(): void
    {
        $response = $this->create('/strict', ['body' => 'Hello', 'author' => 'mallory']);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([['ATTRIBUTE_WRITE_PROHIBITED', '/data/attributes/author']], $this->errors($response));
    }

    #[Test]
    public function theRejectionWinsOverAValidationFailure(): void
    {
        $response = $this->create('/strict', ['body' => 'x', 'author' => 'mallory']);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    public function anAttributeWritableOnCreateIsRejectedOnUpdate(): void
    {
        self::assertSame(201, $this->create('/strict', ['body' => 'Hello', 'slug' => 'hello'])->getStatusCode());

        $response = $this->handle('/strict/memos/1', 'PATCH', ['data' => ['type' => 'memos', 'id' => '1', 'attributes' => ['slug' => 'changed', 'author' => 'mallory']]]);
        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            [['ATTRIBUTE_WRITE_PROHIBITED', '/data/attributes/author'], ['ATTRIBUTE_WRITE_PROHIBITED', '/data/attributes/slug']],
            $this->errors($response),
        );
    }

    #[Test]
    public function onlyTheStrictDocumentListsTheRejectionError(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);

        self::assertStringContainsString('ATTRIBUTE_WRITE_PROHIBITED', $factory->forServer('strict')->toJsonString());
        self::assertStringNotContainsString('ATTRIBUTE_WRITE_PROHIBITED', $factory->forServer('default')->toJsonString());
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function create(string $mount, array $attributes): Response
    {
        return $this->handle($mount . '/memos', 'POST', ['data' => ['type' => 'memos', 'attributes' => $attributes]]);
    }

    /**
     * @return list<array{0: mixed, 1: mixed}>
     */
    private function errors(Response $response): array
    {
        $errors = $this->member($this->decode($response), 'errors');
        self::assertIsArray($errors);

        $out = [];
        foreach ($errors as $error) {
            self::assertIsArray($error);
            $out[] = [$error['code'] ?? null, $this->member($error, 'source', 'pointer')];
        }
        \usort($out, static fn(array $a, array $b): int => \strcmp((string) \json_encode($a), (string) \json_encode($b)));

        return $out;
    }

    /**
     * @param array<mixed> $document
     */
    private function member(array $document, int|string ...$keys): mixed
    {
        $value = $document;
        foreach ($keys as $key) {
            if (!\is_array($value)) {
                return null;
            }
            $value = $value[$key] ?? null;
        }

        return $value;
    }
}
