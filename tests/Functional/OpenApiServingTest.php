<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\OpenApiTestKernel;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Slice-4 stage-B serving witness: boots the {@see OpenApiTestKernel}, hits
 * `GET /docs.json`, and asserts the served OpenAPI document is **well-formed** (a valid
 * OAS 3.1 document by the official meta-schema) and **structurally matches** the
 * kernel's actual surface — the products/categories paths exist with the right methods,
 * the known components are present, the configured + default tags appear, a secured
 * operation carries security, and the collection parameters reflect the declared
 * filters/sorts/paginator.
 */
final class OpenApiServingTest extends JsonApiFunctionalTestCase
{
    private const OAS_SCHEMA_ID = 'https://spec.openapis.org/oas/3.1/schema/2022-10-07';

    protected static function getKernelClass(): string
    {
        return OpenApiTestKernel::class;
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theDocumentIsServedAsApplicationJson(): void
    {
        $response = $this->handle('/docs.json');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theServedDocumentValidatesAgainstTheOas31MetaSchema(): void
    {
        // Decode the RAW wire JSON to the stdClass form (not the assoc array): an
        // empty Schema serializes as `{}` on the wire, and only the object decode
        // preserves it as an object (an assoc decode collapses `{}` to `[]`).
        $response = $this->handle('/docs.json');
        $document = \json_decode((string) $response->getContent(), false, 512, \JSON_THROW_ON_ERROR);

        $result = $this->metaValidator()->validate($document, self::OAS_SCHEMA_ID);

        self::assertTrue(
            $result->isValid(),
            'The served OpenAPI document is not a valid OpenAPI 3.1 document.',
        );
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theDocumentInfoComesFromConfig(): void
    {
        $document = $this->fetchDocument();

        self::assertSame('3.1.0', $document['openapi'] ?? null);
        $info = $this->nested($document, 'info');
        self::assertSame('Catalog API', $info['title'] ?? null);
        self::assertSame('2.0.0', $info['version'] ?? null);
        self::assertSame('A JSON:API catalog surface.', $info['description'] ?? null);
        // The advertised server is derived from the JSON:API server's base URI.
        $server = $this->nested($document, 'servers', '0');
        self::assertSame('https://catalog.test', $server['url'] ?? null);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function thePathsMatchTheRegisteredResourcesAndMethods(): void
    {
        $paths = $this->nested($this->fetchDocument(), 'paths');

        // Products: full CRUD + relationship endpoints + the collection action.
        self::assertArrayHasKey('/products', $paths);
        self::assertArrayHasKey('get', $this->nested($paths, '/products'));
        self::assertArrayHasKey('post', $this->nested($paths, '/products'));
        self::assertArrayHasKey('/products/{id}', $paths);
        self::assertArrayHasKey('get', $this->nested($paths, '/products/{id}'));
        self::assertArrayHasKey('patch', $this->nested($paths, '/products/{id}'));
        self::assertArrayHasKey('delete', $this->nested($paths, '/products/{id}'));
        // The relation endpoints (category to-one, tags to-many).
        self::assertArrayHasKey('/products/{id}/category', $paths);
        self::assertArrayHasKey('/products/{id}/relationships/category', $paths);
        // The custom collection action under the -actions segment.
        self::assertArrayHasKey('/products/-actions/recalculate-prices', $paths);
        self::assertArrayHasKey('post', $this->nested($paths, '/products/-actions/recalculate-prices'));

        // The action returns 204 (returns204: true), so the document advertises a 204
        // response and NOT a 200 document body (§4.5).
        $actionResponses = $this->nested($paths, '/products/-actions/recalculate-prices', 'post', 'responses');
        self::assertArrayHasKey('204', $actionResponses);
        self::assertArrayNotHasKey('200', $actionResponses);

        // Categories registers its own CRUD surface.
        self::assertArrayHasKey('/categories', $paths);
        self::assertArrayHasKey('/categories/{id}', $paths);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theComponentsCoverTheKnownTypesAndTheNamedEnum(): void
    {
        $schemas = $this->nested($this->fetchDocument(), 'components', 'schemas');

        // Component bases are the title-cased type (ComponentNaming::base), so
        // `products` → `Products…`.
        self::assertArrayHasKey('ProductsResource', $schemas);
        self::assertArrayHasKey('ProductsCreateRequest', $schemas);
        self::assertArrayHasKey('ProductsUpdateRequest', $schemas);
        self::assertArrayHasKey('CategoriesResource', $schemas);
        // The shared error document.
        self::assertArrayHasKey('ErrorDocument', $schemas);
        // The backed enum is hoisted into a reusable named component (§4.8).
        self::assertArrayHasKey('CatalogStatus', $schemas);
        self::assertSame(['draft', 'published', 'archived'], $this->nested($schemas, 'CatalogStatus')['enum'] ?? null);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theConfiguredAndDefaultTagsAreEmitted(): void
    {
        $document = $this->fetchDocument();
        $tags = $this->nested($document, 'tags');

        $tagNames = \array_column($tags, 'name');
        // The config-defined Catalog tag (authoritative, carries its description).
        self::assertContains('Catalog', $tagNames);
        $catalog = $this->tagNamed($tags, 'Catalog');
        self::assertSame('Products and catalog actions', $catalog['description'] ?? null);
        // The categories resource declared no tags, so its operations group under the
        // synthesized humanized-default tag.
        self::assertContains('Categories', $tagNames);

        // The products operations carry the Catalog tag.
        self::assertContains('Catalog', $this->nested($document, 'paths', '/products', 'get', 'tags'));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aSecuredOperationCarriesSecurityAndTheSchemeIsDeclared(): void
    {
        $document = $this->fetchDocument();

        // The bearer scheme from config.
        $schemes = $this->nested($document, 'components', 'securitySchemes');
        self::assertArrayHasKey('bearer', $schemes);
        self::assertSame('http', $this->nested($schemes, 'bearer')['type'] ?? null);

        // products read (FetchOne) and create are secured (securityRead/securityCreate),
        // so they carry a security requirement; the collection read is not.
        self::assertArrayHasKey('security', $this->nested($document, 'paths', '/products/{id}', 'get'));
        self::assertArrayHasKey('security', $this->nested($document, 'paths', '/products', 'post'));
        self::assertArrayNotHasKey('security', $this->nested($document, 'paths', '/products', 'get'));

        // The secured action carries security too.
        self::assertArrayHasKey('security', $this->nested($document, 'paths', '/products/-actions/recalculate-prices', 'post'));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theCollectionParametersReflectTheDeclaredFiltersSortsAndPaginator(): void
    {
        $parameters = $this->nested($this->fetchDocument(), 'paths', '/products', 'get', 'parameters');
        $names = \array_column($parameters, 'name');

        // One filter param per declared filter.
        self::assertContains('filter[name]', $names);
        self::assertContains('filter[nameContains]', $names);
        self::assertContains('filter[status]', $names);
        // The sort param enumerates the sortable keys (± desc).
        self::assertContains('sort', $names);
        // include + the sparse-fieldset param for the primary type.
        self::assertContains('include', $names);
        self::assertContains('fields[products]', $names);
        // The page params (page-based paginator).
        self::assertContains('page[number]', $names);
        self::assertContains('page[size]', $names);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDocument(): array
    {
        $response = $this->handle('/docs.json');
        self::assertSame(200, $response->getStatusCode());

        $decoded = \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Narrows a nested array path, asserting each level is an array — so the deeper
     * offset access PHPStan sees stays typed rather than `mixed`.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function nested(array $data, string ...$keys): array
    {
        $current = $data;
        foreach ($keys as $key) {
            self::assertIsArray($current);
            self::assertArrayHasKey($key, $current);
            $current = $current[$key];
        }

        self::assertIsArray($current);

        return $current;
    }

    /**
     * @param array<array-key, mixed> $tags
     *
     * @return array<array-key, mixed>
     */
    private function tagNamed(array $tags, string $name): array
    {
        foreach ($tags as $tag) {
            if (\is_array($tag) && ($tag['name'] ?? null) === $name) {
                return $tag;
            }
        }

        self::fail(\sprintf('Tag "%s" not found.', $name));
    }

    private function metaValidator(): Validator
    {
        $validator = new Validator();
        $resolver = $validator->resolver();
        self::assertNotNull($resolver);

        $register = function (string $base, array $documents) use ($resolver): void {
            foreach ($documents as $document) {
                $raw = \file_get_contents($base . $document);
                self::assertIsString($raw);
                $decoded = \json_decode($raw);
                self::assertInstanceOf(\stdClass::class, $decoded);
                $id = $decoded->{'$id'} ?? null;
                self::assertIsString($id);
                $resolver->registerRaw($decoded, $id);
            }
        };

        $base = \dirname(__DIR__) . '/Functional/App/OpenApi/Fixture/';
        $register($base . 'meta-schema/', [
            'schema.json',
            'meta/core.json',
            'meta/applicator.json',
            'meta/unevaluated.json',
            'meta/validation.json',
            'meta/meta-data.json',
            'meta/format-annotation.json',
            'meta/content.json',
        ]);
        $register($base . 'oas-3.1/', [
            'schema.json',
            'dialect.json',
            'meta/base.json',
        ]);

        return $validator;
    }
}
