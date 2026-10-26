<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A relation that declares fillUsing() is written by that hook on every write path,
 * not by the persister: `fill-files.target` is a MorphTo stored as two columns, and
 * `watchers` a list of ids. The linkage is still resolved through the scope first, so
 * the hidden agency 2 and member 2 are rejected like missing ones, and the update gate
 * still judges the changed file.
 */
abstract class FillUsingConformanceTestCase extends JsonApiFunctionalTestCase
{
    /**
     * @return array{0: ?string, 1: ?string} the stored target type and id
     */
    abstract protected function storedTarget(int $file): array;

    /**
     * @return list<string>
     */
    abstract protected function storedWatchers(int $file): array;

    abstract protected function storedFileCount(): int;

    #[Test]
    public function aCreateWritesTheTargetThroughTheHook(): void
    {
        $response = $this->handle('/fill-files', 'POST', ['data' => [
            'type' => 'fill-files',
            'attributes' => ['name' => 'brief.pdf'],
            'relationships' => [
                'target' => ['data' => ['type' => 'fill-members', 'id' => '3']],
                'watchers' => ['data' => [['type' => 'fill-members', 'id' => '1'], ['type' => 'fill-members', 'id' => '3']]],
            ],
        ]]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $id = $this->member($this->decode($response), 'data', 'id');
        self::assertIsString($id);
        self::assertSame(['fill-members', '3'], $this->storedTarget((int) $id));
        self::assertSame(['1', '3'], $this->storedWatchers((int) $id));

        $relationships = $this->relationshipsOf('/fill-files/' . $id);
        self::assertSame(['type' => 'fill-members', 'id' => '3'], $relationships['target']['data'] ?? null);
    }

    #[Test]
    public function aCreateTargetingAHiddenRowIsRejectedLikeAMissingOne(): void
    {
        foreach (['2', '99'] as $id) {
            $response = $this->handle('/fill-files', 'POST', ['data' => [
                'type' => 'fill-files',
                'attributes' => ['name' => 'brief.pdf'],
                'relationships' => ['target' => ['data' => ['type' => 'fill-agencies', 'id' => $id]]],
            ]]);
            self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
        }

        self::assertSame(1, $this->storedFileCount());
    }

    #[Test]
    public function anUpdateWritesTheTargetThroughTheHook(): void
    {
        $response = $this->handle('/fill-files/1', 'PATCH', ['data' => [
            'type' => 'fill-files',
            'id' => '1',
            'relationships' => [
                'target' => ['data' => ['type' => 'fill-members', 'id' => '1']],
                'watchers' => ['data' => [['type' => 'fill-members', 'id' => '3']]],
            ],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(['fill-members', '1'], $this->storedTarget(1));
        self::assertSame(['3'], $this->storedWatchers(1));
    }

    #[Test]
    public function anUpdateTheGateRefusesAfterTheHookRanCommitsNothing(): void
    {
        $response = $this->handle('/fill-files/1', 'PATCH', ['data' => [
            'type' => 'fill-files',
            'id' => '1',
            'attributes' => ['name' => 'renamed.png'],
            'relationships' => ['target' => ['data' => ['type' => 'fill-agencies', 'id' => '3']]],
        ]]);
        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(['fill-agencies', '1'], $this->storedTarget(1));
    }

    #[Test]
    public function theToOneRelationshipEndpointWritesThroughTheHook(): void
    {
        $response = $this->handle('/fill-files/1/relationships/target', 'PATCH', ['data' => ['type' => 'fill-members', 'id' => '3']]);
        self::assertContains($response->getStatusCode(), [200, 204], (string) $response->getContent());
        self::assertSame(['fill-members', '3'], $this->storedTarget(1));

        $hidden = $this->handle('/fill-files/1/relationships/target', 'PATCH', ['data' => ['type' => 'fill-members', 'id' => '2']]);
        self::assertSame(404, $hidden->getStatusCode(), (string) $hidden->getContent());
        self::assertSame(['fill-members', '3'], $this->storedTarget(1));

        $refused = $this->handle('/fill-files/1/relationships/target', 'PATCH', ['data' => ['type' => 'fill-agencies', 'id' => '3']]);
        self::assertSame(403, $refused->getStatusCode(), (string) $refused->getContent());
        self::assertSame(['fill-members', '3'], $this->storedTarget(1));

        $cleared = $this->handle('/fill-files/1/relationships/target', 'PATCH', ['data' => null]);
        self::assertContains($cleared->getStatusCode(), [200, 204], (string) $cleared->getContent());
        self::assertSame([null, null], $this->storedTarget(1));
    }

    #[Test]
    public function theToManyRelationshipEndpointsPassTheirModeToTheHook(): void
    {
        $add = $this->handle('/fill-files/1/relationships/watchers', 'POST', ['data' => [['type' => 'fill-members', 'id' => '3']]]);
        self::assertContains($add->getStatusCode(), [200, 204], (string) $add->getContent());
        self::assertSame(['1', '3'], $this->storedWatchers(1));

        $remove = $this->handle('/fill-files/1/relationships/watchers', 'DELETE', ['data' => [['type' => 'fill-members', 'id' => '1']]]);
        self::assertContains($remove->getStatusCode(), [200, 204], (string) $remove->getContent());
        self::assertSame(['3'], $this->storedWatchers(1));

        $replace = $this->handle('/fill-files/1/relationships/watchers', 'PATCH', ['data' => [['type' => 'fill-members', 'id' => '1']]]);
        self::assertContains($replace->getStatusCode(), [200, 204], (string) $replace->getContent());
        self::assertSame(['1'], $this->storedWatchers(1));

        $hidden = $this->handle('/fill-files/1/relationships/watchers', 'POST', ['data' => [['type' => 'fill-members', 'id' => '2']]]);
        self::assertSame(404, $hidden->getStatusCode(), (string) $hidden->getContent());
        self::assertSame(['1'], $this->storedWatchers(1));
    }

    #[Test]
    public function anAtomicAddWritesTheTargetThroughTheHook(): void
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $media = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';
        $body = \json_encode(['atomic:operations' => [[
            'op' => 'add',
            'data' => [
                'type' => 'fill-files',
                'attributes' => ['name' => 'atomic.png'],
                'relationships' => ['target' => ['data' => ['type' => 'fill-agencies', 'id' => '3']]],
            ],
        ]]], \JSON_THROW_ON_ERROR);
        $response = $kernel->handle(
            Request::create('/operations', 'POST', server: ['HTTP_ACCEPT' => $media, 'CONTENT_TYPE' => $media], content: $body),
            HttpKernelInterface::MAIN_REQUEST,
            true,
        );
        $this->restoreHandlers();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $id = $this->member($this->decode($response), 'atomic:results', 0, 'data', 'id');
        self::assertIsString($id);
        self::assertSame(['fill-agencies', '3'], $this->storedTarget((int) $id));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function relationshipsOf(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $relationships = $this->member($this->decode($response), 'data', 'relationships');
        self::assertIsArray($relationships);

        /** @var array<string, array<string, mixed>> $relationships */
        return $relationships;
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
