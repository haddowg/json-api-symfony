<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\WrittenNumbers\WrittenNumbersKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * JSON can't tell `2` from `2.0`. A whole number written where an integer is declared is
 * an `int`, and an integer written where a number is declared a `float`, before the
 * constraints compare it, a hook receives it or the store keeps it.
 */
final class WrittenNumbersTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return WrittenNumbersKernel::class;
    }

    #[Test]
    public function anIntegerInASetTakesAWholeFloat(): void
    {
        $attributes = $this->created(['picked' => 2.0, 'ranked' => 2.0, 'stats' => ['plays' => 2.0]]);

        self::assertSame(2, $attributes['picked'] ?? null);
        self::assertSame(2, $attributes['ranked'] ?? null);
        self::assertSame(['plays' => 2], $attributes['stats'] ?? null);
    }

    #[Test]
    public function anIntegerOutsideASetRefusesAWholeFloat(): void
    {
        self::assertSame(['/data/attributes/avoided'], $this->refused(['avoided' => 2.0]));
    }

    #[Test]
    public function aNumberInASetTakesAnInteger(): void
    {
        // A response renders a whole float as `2`, as JSON may.
        $attributes = $this->created(['weight' => 2, 'whole' => 2]);

        self::assertEquals(2, $attributes['weight'] ?? null);
        self::assertEquals(2, $attributes['whole'] ?? null);
        self::assertEquals(2, $this->created(['whole' => 2.0])['whole'] ?? null);
    }

    #[Test]
    public function aNumberOutsideASetRefusesAnInteger(): void
    {
        self::assertSame(['/data/attributes/avoidedWeight'], $this->refused(['avoidedWeight' => 2]));
    }

    #[Test]
    public function anIntegerListStoresInts(): void
    {
        self::assertSame([5, 6], $this->created(['counts' => [5.0, 6]])['counts'] ?? null);
    }

    #[Test]
    public function anIntegerListsUniquenessSeesAWholeFloatAsItsInt(): void
    {
        self::assertSame(['/data/attributes/counts'], $this->refused(['counts' => [1, 1.0]]));
    }

    #[Test]
    public function aHookReceivesAnInt(): void
    {
        self::assertSame('int', $this->created(['hooked' => 5.0])['hookedType'] ?? null);
    }

    #[Test]
    public function anAtomicOperationIsNormalizedToo(): void
    {
        $response = $this->atomic([['op' => 'add', 'data' => ['type' => 'numberCards', 'attributes' => ['picked' => 3.0, 'hooked' => 4.0]]]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $results = $this->decode($response)['atomic:results'] ?? null;
        self::assertIsArray($results);
        $result = $results[0] ?? null;
        self::assertIsArray($result);
        $data = $result['data'] ?? null;
        self::assertIsArray($data);
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame(3, $attributes['picked'] ?? null);
        self::assertSame('int', $attributes['hookedType'] ?? null);
    }

    #[Test]
    public function anActionsInputIsNormalized(): void
    {
        $response = $this->send('/numberCards/-actions/score', ['data' => ['type' => 'numberCards', 'attributes' => [
            'picked' => 2.0,
            'weight' => 2,
            'hooked' => 4.0,
            'counts' => [1.0],
            'weights' => [1, 2.5],
        ]]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            ['picked' => 'int', 'weight' => 'float', 'hooked' => 'int', 'counts' => ['int'], 'weights' => ['float', 'float']],
            $this->decode($response)['meta'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array<array-key, mixed>
     */
    private function created(array $attributes): array
    {
        $response = $this->send('/numberCards', ['data' => ['type' => 'numberCards', 'attributes' => $attributes]]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $rendered = $data['attributes'] ?? null;
        self::assertIsArray($rendered);

        return $rendered;
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return list<mixed>
     */
    private function refused(array $attributes): array
    {
        $response = $this->send('/numberCards', ['data' => ['type' => 'numberCards', 'attributes' => $attributes]]);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());

        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);

        return \array_map(static fn(mixed $error): mixed => \is_array($error) && \is_array($error['source'] ?? null) ? $error['source']['pointer'] ?? null : null, \array_values($errors));
    }

    /**
     * @param list<array<string, mixed>> $operations
     */
    private function atomic(array $operations): Response
    {
        return $this->send('/operations', ['atomic:operations' => $operations], 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"');
    }

    /**
     * POSTs `$body` keeping a whole float's `.0`, as a client writing `2.0` sends it.
     *
     * @param array<string, mixed> $body
     */
    private function send(string $path, array $body, string $mediaType = 'application/vnd.api+json'): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $response = $kernel->handle(Request::create(
            $path,
            'POST',
            server: ['CONTENT_TYPE' => $mediaType, 'HTTP_ACCEPT' => $mediaType],
            content: \json_encode($body, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
        ), HttpKernelInterface::MAIN_REQUEST, true);
        $this->restoreHandlers();

        return $response;
    }
}
