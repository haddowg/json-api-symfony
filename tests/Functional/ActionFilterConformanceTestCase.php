<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * Collection actions taking their type's filters, run against the in-memory kernel
 * ({@see InMemoryActionFilterTest}) and the Doctrine one ({@see DoctrineActionFilterTest}).
 *
 * Both seed the same six `filterCrates`; crate 4 is `retired`, which the `lifecycle`
 * filter's `live` default leaves out. Only an admin may list the collection.
 */
#[Group('spec:actions')]
abstract class ActionFilterConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    public function anActionWithNoFilterReadsTheDefaultedCollectionInIdOrder(): void
    {
        self::assertSame(['1', '2', '3', '5', '6'], $this->ids('/filterCrates/-actions/tally'));
    }

    #[Test]
    public function anActionAppliesEveryFilterTheTypeDeclares(): void
    {
        self::assertSame(['1', '3', '6'], $this->ids('/filterCrates/-actions/tally?filter[status]=open'));
        self::assertSame(['1'], $this->ids('/filterCrates/-actions/tally?filter[status]=open&filter[weight]=3'));
        self::assertSame(['4'], $this->ids('/filterCrates/-actions/tally?filter[lifecycle]=retired'));
        self::assertSame(['5'], $this->ids('/filterCrates/-actions/tally?filter[name]=epsilon'));
    }

    #[Test]
    public function theActionReadsTheRowsTheCollectionFetchLists(): void
    {
        $response = $this->send('/filterCrates?filter[status]=closed', 'GET');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $listed = \array_map(static fn(mixed $row): mixed => \is_array($row) ? $row['id'] ?? null : null, $data);
        \sort($listed);

        // The collection has no default order; the action reads in id order.
        self::assertSame($listed, $this->ids('/filterCrates/-actions/tally?filter[status]=closed'));
    }

    #[Test]
    public function aListedSubsetOfFiltersApplies(): void
    {
        self::assertSame(['2', '5'], $this->ids('/filterCrates/-actions/audit?filter[status]=closed'));
    }

    #[Test]
    public function aDeclaredFilterTheActionDoesNotListIsA400(): void
    {
        $this->assertBadFilter($this->send('/filterCrates/-actions/audit?filter[weight]=3'), 'FILTERING_UNRECOGNIZED', 'filter[weight]');
    }

    #[Test]
    public function anUnknownFilterIsA400(): void
    {
        $this->assertBadFilter($this->send('/filterCrates/-actions/tally?filter[colour]=red'), 'FILTERING_UNRECOGNIZED', 'filter[colour]');
    }

    #[Test]
    public function aValueTheFilterRejectsIsA400(): void
    {
        $this->assertBadFilter($this->send('/filterCrates/-actions/tally?filter[weight]=heavy'), 'FILTER_VALUE_INVALID', 'filter[weight]');
    }

    #[Test]
    public function aCallerWhoCannotListTheCollectionIsRefused(): void
    {
        $listing = $this->send('/filterCrates', 'GET', 'user');
        self::assertSame(403, $listing->getStatusCode(), (string) $listing->getContent());

        $response = $this->send('/filterCrates/-actions/tally', 'POST', 'user');
        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('ACCESS_DENIED', $this->firstError($response)['code'] ?? null);
    }

    #[Test]
    public function anActionTakingNoFiltersSkipsTheCollectionGate(): void
    {
        $response = $this->send('/filterCrates/-actions/ping', 'POST', 'user');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $meta = $this->decode($response)['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertIsString($meta['filtered'] ?? null);
        self::assertStringContainsString('takes no filters', $meta['filtered']);
    }

    #[Test]
    public function theActionsOwnSecurityStillRuns(): void
    {
        $response = $this->send('/filterCrates/-actions/purge?filter[status]=open');
        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    public function theDocumentListsTheFiltersEachActionTakes(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $document = $factory->forServer()->toArray();

        self::assertSame(
            ['filter[status]', 'filter[weight]', 'filter[name]', 'filter[lifecycle]'],
            $this->filterParameters($document, '/filterCrates/-actions/tally'),
        );
        self::assertSame(['filter[status]'], $this->filterParameters($document, '/filterCrates/-actions/audit'));
        self::assertSame([], $this->filterParameters($document, '/filterCrates/-actions/ping'));
    }

    /**
     * @return list<mixed>
     */
    protected function ids(string $path): array
    {
        $meta = $this->meta($path);
        $ids = $meta['ids'] ?? null;
        self::assertIsArray($ids);

        return \array_values($ids);
    }

    /**
     * @return array<string, mixed>
     */
    protected function meta(string $path): array
    {
        $response = $this->send($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $meta = $this->decode($response)['meta'] ?? null;
        self::assertIsArray($meta);

        /** @var array<string, mixed> $meta */
        return $meta;
    }

    protected function send(string $path, string $method = 'POST', string $user = 'admin'): Response
    {
        return $this->handle($path, $method, null, ['HTTP_AUTHORIZATION' => 'Bearer ' . $user]);
    }

    private function assertBadFilter(Response $response, string $code, string $parameter): void
    {
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        $error = $this->firstError($response);
        self::assertSame($code, $error['code'] ?? null);
        $source = $error['source'] ?? null;
        self::assertIsArray($source);
        self::assertSame($parameter, $source['parameter'] ?? null);
    }

    /**
     * @return array<mixed>
     */
    private function firstError(Response $response): array
    {
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        $error = $errors[0] ?? null;
        self::assertIsArray($error);

        return $error;
    }

    /**
     * @param array<mixed> $document
     *
     * @return list<string>
     */
    private function filterParameters(array $document, string $path): array
    {
        $paths = $document['paths'] ?? null;
        self::assertIsArray($paths);
        $item = $paths[$path] ?? null;
        self::assertIsArray($item);
        $operation = $item['post'] ?? null;
        self::assertIsArray($operation);

        $names = [];
        $parameters = $operation['parameters'] ?? [];
        self::assertIsArray($parameters);
        foreach ($parameters as $parameter) {
            if (\is_array($parameter) && isset($parameter['$ref']) && \is_string($parameter['$ref'])) {
                $parameter = $this->resolve($document, $parameter['$ref']);
            }
            $name = \is_array($parameter) ? $parameter['name'] ?? null : null;
            if (\is_string($name) && \str_starts_with($name, 'filter')) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param array<mixed> $document
     */
    private function resolve(array $document, string $ref): mixed
    {
        $node = $document;
        foreach (\array_slice(\explode('/', $ref), 1) as $segment) {
            self::assertIsArray($node);
            $node = $node[$segment] ?? null;
        }

        return $node;
    }
}
