<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A resource that implements InstantiatesEntityInterface builds the model a create
 * hydrates, so its constructor runs, and one that implements DeletesEntityInterface
 * deletes in place of the persister. Note 1 is seeded.
 */
abstract class EntityHooksConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    public function aCreateHydratesTheModelTheResourceBuilt(): void
    {
        $response = $this->handle('/hooked-notes', 'POST', ['data' => ['type' => 'hooked-notes', 'attributes' => ['body' => 'Hello']]]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $attributes = $this->member($this->decode($response), 'data', 'attributes');
        self::assertSame(['body' => 'Hello', 'createdVia' => 'constructor', 'deleted' => false], $attributes);
    }

    #[Test]
    public function anAtomicAddHydratesTheModelTheResourceBuilt(): void
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $media = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';
        $body = \json_encode(['atomic:operations' => [[
            'op' => 'add',
            'data' => ['type' => 'hooked-notes', 'attributes' => ['body' => 'Batched']],
        ]]], \JSON_THROW_ON_ERROR);
        $response = $kernel->handle(
            Request::create('/operations', 'POST', server: ['HTTP_ACCEPT' => $media, 'CONTENT_TYPE' => $media], content: $body),
            HttpKernelInterface::MAIN_REQUEST,
            true,
        );
        $this->restoreHandlers();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame('constructor', $this->member($this->decode($response), 'atomic:results', 0, 'data', 'attributes', 'createdVia'));
    }

    #[Test]
    public function aDeleteRunsTheResourcesDeleteInsteadOfThePersisters(): void
    {
        self::assertSame(204, $this->handle('/hooked-notes/1', 'DELETE')->getStatusCode());

        $response = $this->handle('/hooked-notes/1');
        self::assertSame(200, $response->getStatusCode(), 'the soft-deleted note is still stored');
        self::assertTrue($this->member($this->decode($response), 'data', 'attributes', 'deleted'));
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
