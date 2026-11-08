<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\ActionCreated\ActionCreatedTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Custom actions declaring `ActionCreated`: the response is sent as `201` when the created
 * document is the only one the action declares, the handler picks when it declares a
 * `200` document too, and the generated document advertises the `201`.
 */
#[Group('spec:actions')]
final class ActionCreatedTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return ActionCreatedTestKernel::class;
    }

    #[Test]
    public function anActionDeclaringOnlyACreatedDocumentAnswers201(): void
    {
        $response = $this->handle('/createdOrders/1/-actions/duplicate', 'POST');

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('https://example.test/createdOrders/1-copy', $response->headers->get('Location'));
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame(['createdOrders', '1-copy'], [$data['type'] ?? null, $data['id'] ?? null]);
    }

    #[Test]
    public function anActionDeclaringBothDocumentsAnswersTheStatusItsHandlerPicks(): void
    {
        self::assertSame(200, $this->handle('/createdOrders/1/-actions/restore', 'POST')->getStatusCode());
        self::assertSame(201, $this->handle('/createdOrders/2/-actions/restore', 'POST')->getStatusCode());
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theDocumentAdvertisesThe201(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $document = $factory->forServer()->toArray();

        $duplicate = $this->responsesOf($document, '/createdOrders/{id}/-actions/duplicate');
        self::assertSame(['201'], \array_map('strval', \array_keys(\array_filter($duplicate, static fn(int|string $status): bool => \str_starts_with((string) $status, '2'), \ARRAY_FILTER_USE_KEY))));
        self::assertSame('The resource the action created.', $this->at($duplicate, '201', 'description'));
        self::assertIsArray($this->at($duplicate, '201', 'headers', 'Location'));

        $restore = $this->responsesOf($document, '/createdOrders/{id}/-actions/restore');
        self::assertArrayHasKey('200', $restore);
        self::assertArrayHasKey('201', $restore);

        self::assertSame(\max(GeneratorContract::BASELINE, GeneratorContract::ACTION_CREATED), $this->at($document, 'info', 'x-generator', 'contract'));
    }

    /**
     * @param array<mixed> $document
     *
     * @return array<int|string, mixed>
     */
    private function responsesOf(array $document, string $path): array
    {
        $responses = $this->at($document, 'paths', $path, 'post', 'responses');
        self::assertIsArray($responses);

        return $responses;
    }

    private function at(mixed $node, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            self::assertIsArray($node);
            self::assertArrayHasKey($key, $node);
            $node = $node[$key];
        }

        return $node;
    }
}
