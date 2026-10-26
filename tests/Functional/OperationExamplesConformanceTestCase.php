<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\Testing\SchemaConformanceTrait;
use haddowg\JsonApiBundle\Testing\SchemaDocumentKind;
use haddowg\JsonApiBundle\Tests\Functional\App\OperationExamples\NoteExamples;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The generated OpenAPI operation examples (core ADR 0176) and how the bundle declares
 * and reports them, against the in-memory ({@see InMemoryOperationExamplesTest}) and
 * Doctrine ({@see DoctrineOperationExamplesTest}) kernels. Note 1 is seeded. `notes`
 * declares an example on every field, overrides `fetchOne` and `create`, and switches
 * `update` and `fetchRelationship.parent` off; `scraps` on `gappy` declares none.
 * `tallies` and `jottings` on `counted` cover the paginators that always count and a
 * relation's declared example id.
 */
#[Group('spec:openapi')]
abstract class OperationExamplesConformanceTestCase extends JsonApiFunctionalTestCase
{
    use SchemaConformanceTrait;

    private const string MEDIA_TYPE = 'application/vnd.api+json';

    #[Test]
    public function aGeneratedExampleShowsTheMembersTheServedResponseRenders(): void
    {
        $example = $this->responseExample($this->document(), '/notes', 'get', '200');
        $served = $this->decode($this->handle('/notes'));

        $generated = $this->member($example, 'data', 0);
        $real = $this->member($served, 'data', 0);
        self::assertIsArray($generated);
        self::assertIsArray($real);
        self::assertSame('notes', $generated['type'] ?? null);
        self::assertSame(['title' => 'Groceries', 'rating' => 4, 'author' => 'system'], $generated['attributes'] ?? null);
        self::assertSame($this->keys($real, 'attributes'), $this->keys($generated, 'attributes'));
        self::assertSame($this->keys($real, 'relationships'), $this->keys($generated, 'relationships'));

        $this->assertResponseMatchesGeneratedSchema((string) \json_encode($example), 'notes', SchemaDocumentKind::Collection);
    }

    #[Test]
    public function anOverrideReplacesOnlyThePartsItNames(): void
    {
        $document = $this->document();

        self::assertSame(NoteExamples::FETCH_ONE, $this->responseExample($document, '/notes/{id}', 'get', '200'));
        self::assertSame(NoteExamples::CREATE_REQUEST, $this->requestExample($document, '/notes', 'post'));

        // The create response is not overridden, so it stays generated: the read-only
        // author is there and the id is the Id field's example.
        $created = $this->responseExample($document, '/notes', 'post', '201');
        self::assertSame('7', $this->member($created, 'data', 'id'));
        self::assertSame('system', $this->member($created, 'data', 'attributes', 'author'));
        $this->assertResponseMatchesGeneratedSchema((string) \json_encode($created), 'notes', SchemaDocumentKind::Single);
    }

    #[Test]
    public function falseLeavesTheOperationWithoutAnExample(): void
    {
        $document = $this->document();

        self::assertNull($this->requestExample($document, '/notes/{id}', 'patch'));
        self::assertNull($this->responseExample($document, '/notes/{id}', 'patch', '200'));
        self::assertNull($this->responseExample($document, '/notes/{id}/relationships/parent', 'get', '200'));

        // The relation's other operations keep theirs.
        self::assertSame(['data' => ['type' => 'notes', 'id' => '7']], $this->requestExample($document, '/notes/{id}/relationships/parent', 'patch'));
    }

    #[Test]
    public function anActionExampleReplacesTheGeneratedResponse(): void
    {
        $document = $this->document();

        self::assertSame(NoteExamples::PIN_RESPONSE, $this->responseExample($document, '/notes/{id}/-actions/pin', 'post', '200'));
        self::assertSame('notes', $this->member($this->responseExample($document, '/notes/{id}/-actions/archive', 'post', '200'), 'data', 'type'));
    }

    #[Test]
    public function aServerWithExamplesOffCarriesNone(): void
    {
        self::assertNotSame([], $this->examplesIn($this->document()));
        self::assertSame([], $this->examplesIn($this->document('plain')));
    }

    #[Test]
    public function theTopLevelSwitchTurnsExamplesOffAndAServerCanTurnThemBackOn(): void
    {
        self::ensureKernelShutdown();
        static::bootKernel(['debug' => false, 'environment' => 'off']);

        self::assertSame([], $this->examplesIn($this->document()));
        self::assertNotSame([], $this->examplesIn($this->document('plain')));
    }

    #[Test]
    public function theExportReportsNothingForAServerWithEveryExample(): void
    {
        $tester = $this->export(['--server' => 'default', '--strict' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('', $tester->getErrorOutput());
        self::assertSame('3.1.0', $this->member($this->json($tester->getDisplay()), 'openapi'));
    }

    #[Test]
    public function theExportWarnsOnStderrAndKeepsStdoutADocument(): void
    {
        $tester = $this->export(['--server' => 'gappy']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('fetchOne.scraps has no example: "scraps" declares no example for "id".', $tester->getErrorOutput());
        self::assertSame('3.1.0', $this->member($this->json($tester->getDisplay()), 'openapi'));
    }

    #[Test]
    public function theStrictExportFailsOnAMissingExampleAndWritesNoDocument(): void
    {
        $tester = $this->export(['--server' => 'gappy', '--strict' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('--strict', $tester->getErrorOutput());
    }

    #[Test]
    public function aServerWithExamplesOffHasNothingToReport(): void
    {
        $tester = $this->export(['--server' => 'plain', '--strict' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('', $tester->getErrorOutput());
    }

    #[Test]
    public function aCollectionWhosePaginatorAlwaysCountsShowsItsTotalWithoutWithCount(): void
    {
        $document = $this->document('counted');

        // `tallies` takes the server default paginator, which always counts.
        $example = $this->responseExample($document, '/tallies', 'get', '200');
        $this->assertCountedWithoutWithCount($example);

        // The served page carries the same members.
        $served = $this->decode($this->handle('/counted/tallies'));
        self::assertIsInt($this->member($served, 'meta', 'total'));
        self::assertNotNull($this->member($served, 'links', 'last'));

        // `jottings` overrides the default with a count-free paginator.
        $this->assertCountFree($this->responseExample($document, '/jottings', 'get', '200'));
    }

    #[Test]
    public function aRelationWhosePaginatorAlwaysCountsShowsItsTotalWithoutWithCount(): void
    {
        $document = $this->document('counted');

        $this->assertCountedWithoutWithCount($this->responseExample($document, '/tallies/{id}/countedChildren', 'get', '200'));
        $this->assertCountedWithoutWithCount($this->responseExample($document, '/tallies/{id}/relationships/countedChildren', 'get', '200'));

        // `children` takes the count-free paginator of `jottings`.
        $this->assertCountFree($this->responseExample($document, '/tallies/{id}/children', 'get', '200'));
        $this->assertCountFree($this->responseExample($document, '/tallies/{id}/relationships/children', 'get', '200'));
    }

    #[Test]
    public function aRelationExampleIdFillsLinkageToAnUnregisteredType(): void
    {
        $document = $this->document('counted');
        $linkage = ['type' => 'notes', 'id' => '9'];

        self::assertSame($linkage, $this->member($this->responseExample($document, '/tallies/{id}/relationships/parent', 'get', '200'), 'data'));
        self::assertSame($linkage, $this->member($this->responseExample($document, '/tallies/{id}', 'get', '200'), 'data', 'relationships', 'parent', 'data'));
    }

    #[Test]
    public function theExportReportsNothingForTheCountedServer(): void
    {
        $tester = $this->export(['--server' => 'counted', '--strict' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('', $tester->getErrorOutput());
    }

    private function assertCountedWithoutWithCount(mixed $example): void
    {
        self::assertIsInt($this->member($example, 'meta', 'total'));
        self::assertIsInt($this->member($example, 'meta', 'page', 'total'));
        self::assertNotNull($this->member($example, 'links', 'last'));
        self::assertStringNotContainsString('withCount', (string) \json_encode($this->member($example, 'links')));
        self::assertNull($this->member($example, 'jsonapi', 'profile'));
    }

    private function assertCountFree(mixed $example): void
    {
        self::assertIsArray($example);
        self::assertNull($this->member($example, 'meta', 'total'));
        self::assertNull($this->member($example, 'meta', 'page', 'total'));
        self::assertNull($this->member($example, 'links', 'last'));
    }

    /**
     * @return array<mixed>
     */
    private function document(string $server = 'default'): array
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);

        return $this->json($factory->forServer($server)->toJsonString());
    }

    /**
     * @param array<mixed> $document
     */
    private function responseExample(array $document, string $path, string $method, string $status): mixed
    {
        return $this->member($document, 'paths', $path, $method, 'responses', $status, 'content', self::MEDIA_TYPE, 'example');
    }

    /**
     * @param array<mixed> $document
     */
    private function requestExample(array $document, string $path, string $method): mixed
    {
        return $this->member($document, 'paths', $path, $method, 'requestBody', 'content', self::MEDIA_TYPE, 'example');
    }

    /**
     * Where a request body, response or shared error response carries an example.
     *
     * @param array<mixed> $document
     *
     * @return list<string>
     */
    private function examplesIn(array $document): array
    {
        $found = [];
        $paths = $this->member($document, 'paths');
        foreach (\is_array($paths) ? $paths : [] as $path => $item) {
            foreach (\is_array($item) ? $item : [] as $method => $operation) {
                if (!\is_array($operation)) {
                    continue;
                }
                if ($this->member($operation, 'requestBody', 'content', self::MEDIA_TYPE, 'example') !== null) {
                    $found[] = $path . ' ' . $method . ' request';
                }
                $responses = $operation['responses'] ?? [];
                foreach (\is_array($responses) ? $responses : [] as $status => $response) {
                    if (\is_array($response) && $this->member($response, 'content', self::MEDIA_TYPE, 'example') !== null) {
                        $found[] = $path . ' ' . $method . ' ' . $status;
                    }
                }
            }
        }

        $shared = $this->member($document, 'components', 'responses');
        foreach (\is_array($shared) ? $shared : [] as $name => $response) {
            if (\is_array($response) && $this->member($response, 'content', self::MEDIA_TYPE, 'example') !== null) {
                $found[] = 'components ' . $name;
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function export(array $input): CommandTester
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $tester = new CommandTester((new Application($kernel))->find('json-api:openapi:export'));
        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
    }

    /**
     * @return array<mixed>
     */
    private function json(string $encoded): array
    {
        $decoded = \json_decode($encoded, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<mixed> $resource
     *
     * @return list<string>
     */
    private function keys(array $resource, string $member): array
    {
        $value = $resource[$member] ?? [];
        self::assertIsArray($value);
        $keys = \array_map('strval', \array_keys($value));
        \sort($keys);

        return $keys;
    }

    private function member(mixed $document, int|string ...$keys): mixed
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
