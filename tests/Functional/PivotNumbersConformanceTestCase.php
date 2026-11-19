<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\PivotNumbers\PnBoard;
use haddowg\JsonApiBundle\Tests\Functional\App\PivotNumbers\PnLink;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A number written to a link row's `count`, an integer, is stored as an `int`, and one
 * written to its `weight`, a number, as a `float`, whether a relationship endpoint or a
 * resource write carries it. A value that is no number is a 422 at its pointer. Board 1
 * links card 1 with a count of 1 and a weight of 1.5.
 */
abstract class PivotNumbersConformanceTestCase extends JsonApiFunctionalTestCase
{
    abstract protected function board(string $id): PnBoard;

    #[Test]
    public function addingALinkRowStoresItsNumbersInTheirFieldsTypes(): void
    {
        $response = $this->send('/pn-boards/1/relationships/links', 'POST', ['data' => [
            ['type' => 'pn-cards', 'id' => '2', 'meta' => ['pivot' => ['count' => 5.0, 'weight' => 5]]],
        ]]);
        self::assertLessThan(300, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(['1' => [1, 1.5], '2' => [5, 5.0]], $this->links('1'));
        self::assertSame(['count' => 5, 'weight' => 5], $this->renderedPivots('/pn-boards/1/relationships/links')['2'] ?? null);
    }

    #[Test]
    public function replacingLinkRowsStoresTheirNumbersInTheirFieldsTypes(): void
    {
        $response = $this->send('/pn-boards/1/relationships/links', 'PATCH', ['data' => [
            ['type' => 'pn-cards', 'id' => '1', 'meta' => ['pivot' => ['count' => 7.0, 'weight' => 2]]],
            ['type' => 'pn-cards', 'id' => '3', 'meta' => ['pivot' => ['count' => 2.0, 'weight' => 3]]],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(['1' => [7, 2.0], '3' => [2, 3.0]], $this->links('1'));
    }

    #[Test]
    public function aCreatedResourcesLinkRowsStoreTheirNumbersInTheirFieldsTypes(): void
    {
        $response = $this->send('/pn-boards', 'POST', ['data' => [
            'type' => 'pn-boards',
            'id' => '2',
            'attributes' => ['name' => 'Side'],
            'relationships' => ['links' => ['data' => [
                ['type' => 'pn-cards', 'id' => '2', 'meta' => ['pivot' => ['count' => 4.0, 'weight' => 4]]],
            ]]],
        ]]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(['2' => [4, 4.0]], $this->links('2'));
    }

    #[Test]
    public function aLinkRowValueThatIsNoNumberIsA422AtItsPointer(): void
    {
        $this->assertRefusedAt('/data/0/meta/pivot/count', $this->send('/pn-boards/1/relationships/links', 'POST', ['data' => [
            ['type' => 'pn-cards', 'id' => '2', 'meta' => ['pivot' => ['count' => 'five', 'weight' => 5]]],
        ]]));
        $this->assertRefusedAt('/data/1/meta/pivot/weight', $this->send('/pn-boards/1/relationships/links', 'PATCH', ['data' => [
            ['type' => 'pn-cards', 'id' => '1', 'meta' => ['pivot' => ['count' => 1, 'weight' => 1.5]]],
            ['type' => 'pn-cards', 'id' => '2', 'meta' => ['pivot' => ['count' => 1, 'weight' => 'heavy']]],
        ]]));
        $this->assertRefusedAt('/data/0/meta/pivot/count', $this->send('/pn-boards/1/relationships/links', 'POST', ['data' => [
            ['type' => 'pn-cards', 'id' => '2', 'meta' => ['pivot' => ['count' => 5.5, 'weight' => 5]]],
        ]]));
        $this->assertRefusedAt('/data/relationships/links/data/0/meta/pivot/count', $this->send('/pn-boards', 'POST', ['data' => [
            'type' => 'pn-boards',
            'id' => '2',
            'attributes' => ['name' => 'Side'],
            'relationships' => ['links' => ['data' => [
                ['type' => 'pn-cards', 'id' => '2', 'meta' => ['pivot' => ['count' => 'four', 'weight' => 4]]],
            ]]],
        ]]));

        self::assertSame(['1' => [1, 1.5]], $this->links('1'));
    }

    /**
     * Each link row of the board as its card id, count and weight, in the PHP types the
     * store holds.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    protected function links(string $board): array
    {
        $links = [];
        foreach ($this->board($board)->links as $link) {
            \assert($link instanceof PnLink);
            $links[$link->itemId] = [$link->count, $link->weight];
        }
        \ksort($links);

        return $links;
    }

    /**
     * Each member's rendered `meta.pivot`, by id.
     *
     * @return array<string, mixed>
     */
    protected function renderedPivots(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        $pivots = [];
        foreach ($data as $member) {
            self::assertIsArray($member);
            self::assertIsString($member['id'] ?? null);
            self::assertIsArray($member['meta'] ?? null);
            $pivots[$member['id']] = $member['meta']['pivot'] ?? null;
        }

        return $pivots;
    }

    protected function assertRefusedAt(string $pointer, Response $response): void
    {
        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        $pointers = \array_map(static fn(mixed $error): mixed => \is_array($error) && \is_array($error['source'] ?? null) ? ($error['source']['pointer'] ?? null) : null, $errors);
        self::assertSame([$pointer], $pointers);
    }

    /**
     * Sends a document keeping each whole float's `.0`, as a client writing `5.0` does.
     *
     * @param array<string, mixed> $body
     */
    protected function send(string $path, string $method, array $body): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $response = $kernel->handle(Request::create($path, $method, server: [
            'HTTP_ACCEPT' => 'application/vnd.api+json',
            'CONTENT_TYPE' => 'application/vnd.api+json',
        ], content: \json_encode($body, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION)), HttpKernelInterface::MAIN_REQUEST, true);
        $this->restoreHandlers();

        return $response;
    }
}
