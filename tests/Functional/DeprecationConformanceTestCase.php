<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deprecation markers in the OpenAPI document and the headers deprecated operations
 * send, against the in-memory ({@see InMemoryDeprecationTest}) and Doctrine
 * ({@see DoctrineDeprecationTest}) kernels. Bulletin 1 and bulletin 2, whose parent is
 * bulletin 1, are seeded, and record 1 of every other type.
 *
 * `bulletins` deprecates `delete`, `fetchRelated.parent`, the `archive` action and some
 * of its members. `memos` is deprecated whole, `pamphlets` whole with a sunset,
 * `leaflets` has a sunset and deprecates only `fetchOne`, and `notices` on the `plain`
 * server declares nothing.
 */
abstract class DeprecationConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string SUNSET_SENTENCE = 'This operation may stop responding on or after %s, its sunset date.';

    #[Test]
    #[Group('spec:openapi')]
    public function aDocumentWithMarkersDeclaresTheDeprecationContract(): void
    {
        self::assertSame(\max(GeneratorContract::BASELINE, GeneratorContract::DEPRECATION), $this->at($this->document(), 'info', 'x-generator', 'contract'));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aDocumentWithoutMarkersKeepsItsStamp(): void
    {
        $document = $this->document('plain');

        self::assertSame(GeneratorContract::BASELINE, $this->at($document, 'info', 'x-generator', 'contract'));
        self::assertStringNotContainsString('"deprecated"', (string) \json_encode($document));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aDeprecatedTypeMarksEveryOperation(): void
    {
        $document = $this->document();

        self::assertTrue($this->at($document, 'paths', '/memos', 'get', 'deprecated'));
        self::assertTrue($this->at($document, 'paths', '/memos/{id}', 'get', 'deprecated'));
        self::assertStringNotContainsString('sunset date', $this->description($document, '/memos/{id}'));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aDeprecatedTypeWithASunsetNamesTheDate(): void
    {
        $document = $this->document();

        foreach (['/pamphlets', '/pamphlets/{id}'] as $path) {
            self::assertTrue($this->at($document, 'paths', $path, 'get', 'deprecated'), $path);
            self::assertStringEndsWith(
                \sprintf(self::SUNSET_SENTENCE, '2050-12-31'),
                $this->description($document, $path),
                $path,
            );
        }
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aSunsetWithoutDeprecationOnlyNamesTheDate(): void
    {
        $document = $this->document();

        self::assertNull($this->at($document, 'paths', '/leaflets', 'get', 'deprecated'));
        self::assertStringEndsWith(
            \sprintf(self::SUNSET_SENTENCE, '2051-06-30'),
            $this->description($document, '/leaflets'),
        );
        self::assertTrue($this->at($document, 'paths', '/leaflets/{id}', 'get', 'deprecated'));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aNamedOperationIsMarkedAndItsSiblingsAreNot(): void
    {
        $document = $this->document();

        self::assertTrue($this->at($document, 'paths', '/bulletins/{id}', 'delete', 'deprecated'));
        self::assertTrue($this->at($document, 'paths', '/bulletins/{id}/parent', 'get', 'deprecated'));
        self::assertNull($this->at($document, 'paths', '/bulletins/{id}', 'get', 'deprecated'));
        self::assertNull($this->at($document, 'paths', '/bulletins/{id}', 'patch', 'deprecated'));
        self::assertNull($this->at($document, 'paths', '/bulletins', 'get', 'deprecated'));
        self::assertNull($this->at($document, 'paths', '/bulletins/{id}/relationships/parent', 'get', 'deprecated'));
        self::assertNull($this->at($document, 'paths', '/bulletins/{id}/editor', 'get', 'deprecated'));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aDeprecatedActionIsMarkedAndItsSiblingIsNot(): void
    {
        $document = $this->document();

        self::assertTrue($this->at($document, 'paths', '/bulletins/{id}/-actions/archive', 'post', 'deprecated'));
        self::assertNull($this->at($document, 'paths', '/bulletins/{id}/-actions/pin', 'post', 'deprecated'));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function deprecatedMembersAreMarked(): void
    {
        $document = $this->document();
        $schemas = $this->at($document, 'components', 'schemas');
        self::assertIsArray($schemas);

        $resource = null;
        foreach ($schemas as $schema) {
            $relationships = \is_array($schema) ? $this->at($schema, 'properties', 'relationships', 'properties') : null;
            if (\is_array($relationships) && isset($relationships['editor'], $relationships['parent'])) {
                $resource = $schema;
                break;
            }
        }
        self::assertIsArray($resource, 'the bulletins resource schema');
        self::assertTrue($this->at($resource, 'properties', 'relationships', 'properties', 'editor', 'deprecated'));
        self::assertNull($this->at($resource, 'properties', 'relationships', 'properties', 'parent', 'deprecated'));

        $attributes = $this->at($resource, 'properties', 'attributes', 'properties');
        if (!\is_array($attributes)) {
            $attributes = $this->resolve($document, $this->at($resource, 'properties', 'attributes'));
            $attributes = \is_array($attributes) ? ($attributes['properties'] ?? null) : null;
        }
        self::assertIsArray($attributes);
        self::assertTrue($this->at($attributes, 'code', 'deprecated'));
        self::assertNull($this->at($attributes, 'title', 'deprecated'));

        $parameters = [];
        $list = $this->at($document, 'paths', '/bulletins', 'get', 'parameters');
        self::assertIsArray($list);
        foreach ($list as $parameter) {
            if (\is_array($parameter) && \is_string($parameter['name'] ?? null)) {
                $parameters[$parameter['name']] = $parameter;
            }
        }
        self::assertTrue($this->at($parameters, 'filter[code]', 'deprecated'));
        self::assertNull($this->at($parameters, 'filter[title]', 'deprecated'));
        self::assertSame(['legacyTitle'], $this->at($parameters, 'sort', 'schema', 'x-deprecated-sorts'));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aDeprecatedOperationSendsTheDeprecationHeader(): void
    {
        $response = $this->handle('/bulletins/2', 'DELETE');

        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('true', $response->headers->get('Deprecation'));
        self::assertFalse($response->headers->has('Sunset'));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aDeprecatedRelatedOperationSendsTheHeaderAndItsRelationshipSiblingDoesNot(): void
    {
        $related = $this->handle('/bulletins/2/parent');
        self::assertSame(200, $related->getStatusCode(), (string) $related->getContent());
        self::assertSame('true', $related->headers->get('Deprecation'));

        $relationship = $this->handle('/bulletins/2/relationships/parent');
        self::assertSame(200, $relationship->getStatusCode(), (string) $relationship->getContent());
        self::assertNoDeprecation($relationship);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aSiblingOperationSendsNoDeprecationHeader(): void
    {
        foreach (['/bulletins/1', '/bulletins'] as $path) {
            $response = $this->handle($path);
            self::assertSame(200, $response->getStatusCode(), $path . ': ' . $response->getContent());
            self::assertNoDeprecation($response);
        }
    }

    #[Test]
    #[Group('spec:actions')]
    public function aDeprecatedActionSendsTheHeaderAndItsSiblingDoesNot(): void
    {
        $archive = $this->handle('/bulletins/1/-actions/archive', 'POST');
        self::assertSame(200, $archive->getStatusCode(), (string) $archive->getContent());
        self::assertSame('true', $archive->headers->get('Deprecation'));

        $pin = $this->handle('/bulletins/1/-actions/pin', 'POST');
        self::assertSame(200, $pin->getStatusCode(), (string) $pin->getContent());
        self::assertNoDeprecation($pin);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aDeprecatedOperationCarriesTheTypesSunset(): void
    {
        $one = $this->handle('/leaflets/1');
        self::assertSame(200, $one->getStatusCode(), (string) $one->getContent());
        self::assertSame('true', $one->headers->get('Deprecation'));
        self::assertSame('2051-06-30', $one->headers->get('Sunset'));

        // The collection is not deprecated, but the type's sunset still applies to it.
        $collection = $this->handle('/leaflets');
        self::assertFalse($collection->headers->has('Deprecation'));
        self::assertSame('2051-06-30', $collection->headers->get('Sunset'));
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aDeprecatedTypeKeepsItsOwnDeprecationValue(): void
    {
        $response = $this->handle('/pamphlets/1');

        self::assertSame('@2556143999', $response->headers->get('Deprecation'));
        self::assertSame('Sat, 31 Dec 2050 23:59:59 GMT', $response->headers->get('Sunset'));
    }

    /**
     * @return array<string, mixed>
     */
    private function document(?string $server = null): array
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);

        return $factory->forServer($server)->toArray();
    }

    /**
     * @param array<string, mixed> $document
     */
    private function description(array $document, string $path): string
    {
        $description = $this->at($document, 'paths', $path, 'get', 'description');
        self::assertIsString($description, $path);

        return $description;
    }

    private function at(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function resolve(array $document, mixed $schema): mixed
    {
        $ref = \is_array($schema) ? ($schema['$ref'] ?? null) : null;
        if (!\is_string($ref) || !\str_starts_with($ref, '#/')) {
            return $schema;
        }

        return $this->at($document, ...\explode('/', \substr($ref, 2)));
    }

    private static function assertNoDeprecation(Response $response): void
    {
        self::assertFalse($response->headers->has('Deprecation'), (string) $response->headers);
        self::assertFalse($response->headers->has('Sunset'));
    }
}
