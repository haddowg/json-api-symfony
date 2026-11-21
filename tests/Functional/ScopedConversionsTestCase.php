<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ScopedConversions\ScJournalResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ScopedConversions\ScMark;
use haddowg\JsonApiBundle\Tests\Functional\App\ScopedConversions\ScMarks;
use haddowg\JsonApiBundle\Tests\Functional\App\ScopedConversions\ScMood;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A conversion declared inside `onCreate()` or `onUpdate()` applies to that write only:
 * `mood` becomes the enum case on a create and stays the string on an update, `name` is
 * upper-cased on an update and kept as sent on a create, and a mark's `tone` becomes the
 * case on a new link row and stays the string on a kept one. Atomic operations and action
 * input follow the same rule. Journals 1 and 2 exist.
 */
abstract class ScopedConversionsTestCase extends JsonApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ScJournalResource::$moods = [];
        ScMarks::$rows = [];
    }

    #[Test]
    public function aCreateConvertsWhatItsScopeDeclares(): void
    {
        $response = $this->send('/sc-journals', 'POST', $this->journal('3'));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('ada', $this->attribute($response, 'name'));
        self::assertSame([ScMood::class], ScJournalResource::$moods);
    }

    #[Test]
    public function anUpdateConvertsWhatItsScopeDeclares(): void
    {
        $response = $this->send('/sc-journals/1', 'PATCH', $this->journal('1'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('ADA', $this->attribute($response, 'name'));
        self::assertSame(['string'], ScJournalResource::$moods);
    }

    #[Test]
    public function anAtomicOperationConvertsWhatItsWriteDeclares(): void
    {
        $response = $this->send('/operations', 'POST', ['atomic:operations' => [
            ['op' => 'add', 'data' => $this->journal('3')['data']],
            ['op' => 'update', 'ref' => ['type' => 'sc-journals', 'id' => '1'], 'data' => $this->journal('1')['data']],
        ]], 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([ScMood::class, 'string'], ScJournalResource::$moods);
    }

    #[Test]
    public function aPostActionsInputConvertsWhatACreateDeclares(): void
    {
        $response = $this->send('/sc-journals/-actions/record', 'POST', $this->journal('3'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['name' => 'ada', 'moods' => [ScMood::class]], $this->decode($response)['meta'] ?? null);
    }

    #[Test]
    public function aPatchActionsInputConvertsWhatAnUpdateDeclares(): void
    {
        $response = $this->send('/sc-journals/-actions/revise', 'PATCH', $this->journal('1'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['name' => 'ADA', 'moods' => ['string']], $this->decode($response)['meta'] ?? null);
    }

    #[Test]
    public function aNewLinkRowConvertsItsPivotValueAndAKeptOneDoesNot(): void
    {
        $response = $this->send('/sc-journals/1/relationships/marks', 'POST', ['data' => [
            ['type' => 'sc-journals', 'id' => '2', 'meta' => ['pivot' => ['tone' => 'calm']]],
        ]]);
        self::assertLessThan(300, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([['2', ScMood::Calm]], $this->tones('1'));

        $response = $this->send('/sc-journals/1/relationships/marks', 'PATCH', ['data' => [
            ['type' => 'sc-journals', 'id' => '2', 'meta' => ['pivot' => ['tone' => 'busy']]],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([['2', 'busy']], $this->tones('1'));
    }

    /**
     * @return array{data: array<string, mixed>}
     */
    private function journal(string $id): array
    {
        return ['data' => ['type' => 'sc-journals', 'id' => $id, 'attributes' => ['name' => 'ada', 'mood' => 'calm']]];
    }

    /**
     * Each of the journal's link rows as its member id and the tone it holds.
     *
     * @return list<array{0: string, 1: mixed}>
     */
    private function tones(string $journal): array
    {
        return \array_values(\array_map(
            static fn(ScMark $row): array => [$row->id, $row->tone],
            \array_filter(ScMarks::$rows, static fn(ScMark $row): bool => $row->journal === $journal),
        ));
    }

    private function attribute(Response $response, string $name): mixed
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsArray($data['attributes'] ?? null);

        return $data['attributes'][$name] ?? null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(string $path, string $method, array $body, string $mediaType = 'application/vnd.api+json'): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $response = $kernel->handle(Request::create($path, $method, server: [
            'HTTP_ACCEPT' => $mediaType,
            'CONTENT_TYPE' => $mediaType,
        ], content: \json_encode($body, \JSON_THROW_ON_ERROR)), HttpKernelInterface::MAIN_REQUEST, true);
        $this->restoreHandlers();

        return $response;
    }
}
