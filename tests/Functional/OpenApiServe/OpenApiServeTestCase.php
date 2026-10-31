<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\OpenApiServe;

use haddowg\JsonApiBundle\OpenApi\ArtifactStore;
use haddowg\JsonApiBundle\OpenApi\DocumentWarmer;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve\FeatureGateDecorator;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve\FeatureGateSchemaDecorator;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve\OpenApiServeTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve\UnkeyedDecorator;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve\UnkeyedSchemaDecorator;
use haddowg\JsonApiBundle\Tests\Functional\JsonApiFunctionalTestCase;
use Opis\JsonSchema\Validator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Shared scaffolding for the serve-time customisation suites: boots
 * {@see OpenApiServeTestKernel} in the environment a subclass names, issues requests on a
 * chosen host, and warms the artifacts.
 */
abstract class OpenApiServeTestCase extends JsonApiFunctionalTestCase
{
    private const string OAS_SCHEMA_ID = 'https://spec.openapis.org/oas/3.1/schema/2022-10-07';

    abstract protected static function environment(): string;

    protected static function getKernelClass(): string
    {
        return OpenApiServeTestKernel::class;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $options['environment'] = static::environment();

        return parent::createKernel($options);
    }

    protected function setUp(): void
    {
        parent::setUp();

        FeatureGateDecorator::$decorated = 0;
        FeatureGateDecorator::$keyed = 0;
        FeatureGateSchemaDecorator::$decorated = 0;
        UnkeyedDecorator::$decorated = 0;
        UnkeyedSchemaDecorator::$decorated = 0;
    }

    /**
     * @param array<string, string> $headers request headers, e.g. `['If-None-Match' => '"abc"']`
     */
    protected function get(string $path, string $host = 'tenant.test', array $headers = []): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $server = ['HTTP_HOST' => $host];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . \strtoupper(\str_replace('-', '_', $name))] = $value;
        }

        $response = $kernel->handle(Request::create($path, 'GET', server: $server), HttpKernelInterface::MAIN_REQUEST, true);
        $this->restoreHandlers();

        return $response;
    }

    protected function warm(): void
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $warmer = static::getContainer()->get(DocumentWarmer::class);
        \assert($warmer instanceof DocumentWarmer);
        $warmer->warmUp($kernel->getCacheDir());
    }

    protected function store(): ArtifactStore
    {
        $store = static::getContainer()->get(ArtifactStore::class);
        \assert($store instanceof ArtifactStore);

        return $store;
    }

    protected function etagOf(Response $response): string
    {
        $etag = $response->getEtag();
        self::assertIsString($etag);

        return $etag;
    }

    protected static function quoted(string $hash): string
    {
        return '"' . $hash . '"';
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(Response $response): array
    {
        $decoded = \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array<array-key, mixed>
     */
    protected static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    protected function assertValidOpenApi(Response $response): void
    {
        // The object decode keeps an empty schema `{}` as an object.
        $document = \json_decode((string) $response->getContent(), false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $document);

        self::assertTrue(
            $this->metaValidator()->validate($document, self::OAS_SCHEMA_ID)->isValid(),
            'The served OpenAPI document is not a valid OpenAPI 3.1 document.',
        );

        // The meta-schema does not resolve references, so check every one lands.
        $components = $this->json($response)['components'] ?? [];
        self::assertIsArray($components);
        foreach ($this->refs($this->json($response)) as $ref) {
            self::assertStringStartsWith('#/components/', $ref);
            [$section, $name] = \explode('/', \substr($ref, \strlen('#/components/')), 2);
            self::assertArrayHasKey($name, self::arr($components[$section] ?? null), \sprintf('Dangling reference %s.', $ref));
        }
    }

    /**
     * @return list<string>
     */
    protected function refs(mixed $node): array
    {
        if (!\is_array($node)) {
            return [];
        }

        $refs = [];
        foreach ($node as $key => $value) {
            if ($key === '$ref' && \is_string($value)) {
                $refs[] = $value;
            } else {
                $refs = [...$refs, ...$this->refs($value)];
            }
        }

        return $refs;
    }

    private function metaValidator(): Validator
    {
        $validator = new Validator();
        $resolver = $validator->resolver();
        self::assertNotNull($resolver);

        $register = static function (string $base, array $documents) use ($resolver): void {
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

        $base = \dirname(__DIR__) . '/App/OpenApi/Fixture/';
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
