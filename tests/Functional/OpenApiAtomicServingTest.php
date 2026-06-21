<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\OpenApiMultiServerTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\OpenApiTestKernel;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The Atomic Operations extension OpenAPI witness: when `json_api.atomic_operations`
 * is enabled, every served per-server document (and the combined document) advertises
 * the `POST /operations` batch endpoint under the extension-qualified media type, the
 * atomic request/result components, and the `Atomic Operations` tag — and when the
 * extension is disabled (the default), none of that appears.
 *
 * The extension is a single GLOBAL flag but the endpoint exists per server (mirroring
 * the {@see \haddowg\JsonApiBundle\Routing\JsonApiRouteLoader}, which emits one
 * `POST {path}` route per server), so the per-server assertions check both the
 * `default` server's document and the named `admin` server's document.
 *
 * Each test boots the specific kernel it needs and restores the global error/exception
 * handler stack (booting a kernel installs Symfony's handlers, which PHPUnit strict
 * mode flags if left on the stack).
 */
final class OpenApiAtomicServingTest extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
{
    private const OAS_SCHEMA_ID = 'https://spec.openapis.org/oas/3.1/schema/2022-10-07';

    private const ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';

    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected function setUp(): void
    {
        $this->errorHandler = \set_error_handler(null);
        \restore_error_handler();
        $this->exceptionHandler = \set_exception_handler(null);
        \restore_exception_handler();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreHandlers();
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theAtomicEndpointIsOmittedWhenTheExtensionIsDisabled(): void
    {
        // The default OpenApiTestKernel does not enable atomic_operations.
        $kernel = new OpenApiTestKernel('test', false);
        $kernel->boot();

        $document = $this->document($kernel, '/docs.json');

        $paths = $this->asArray($document['paths'] ?? null);
        self::assertArrayNotHasKey('/operations', $paths);

        $schemas = $this->asArray($this->asArray($document['components'] ?? null)['schemas'] ?? null);
        self::assertArrayNotHasKey('AtomicOperationsRequest', $schemas);
        self::assertArrayNotHasKey('AtomicResultsResponse', $schemas);

        $tagNames = $this->tagNames($document);
        self::assertNotContains('Atomic Operations', $tagNames);

        $kernel->shutdown();
    }

    #[Test]
    #[Group('spec:openapi')]
    public function eachServersDocumentAdvertisesTheAtomicEndpointWhenEnabled(): void
    {
        $kernel = new OpenApiMultiServerTestKernel('test', false, false, true);
        $kernel->boot();

        // The extension is a single global flag, but the endpoint (and its OpenAPI
        // path/components/tag) exists per server: both the default and the admin
        // server's documents carry it.
        foreach (['/docs.json', '/admin/docs.json'] as $docPath) {
            $document = $this->document($kernel, $docPath);

            $paths = $this->asArray($document['paths'] ?? null);
            self::assertArrayHasKey('/operations', $paths, $docPath . ' is missing the atomic endpoint');

            $post = $this->asArray($this->asArray($paths['/operations'])['post'] ?? null);

            // The request body and the 200 response are carried under the
            // extension-qualified JSON:API media type.
            $requestContent = $this->asArray($this->asArray($post['requestBody'] ?? null)['content'] ?? null);
            self::assertArrayHasKey(self::ATOMIC_MEDIA_TYPE, $requestContent);

            $okContent = $this->asArray(
                $this->asArray($this->asArray($post['responses'] ?? null)['200'] ?? null)['content'] ?? null,
            );
            self::assertArrayHasKey(self::ATOMIC_MEDIA_TYPE, $okContent);

            // The operation is grouped under the Atomic Operations tag, and that tag is
            // defined at the document root (so the ref resolves).
            self::assertContains('Atomic Operations', $this->asArray($post['tags'] ?? null));
            self::assertContains('Atomic Operations', $this->tagNames($document));

            // The atomic request/result documents are emitted as components.
            $schemas = $this->asArray($this->asArray($document['components'] ?? null)['schemas'] ?? null);
            self::assertArrayHasKey('AtomicOperationsRequest', $schemas);
            self::assertArrayHasKey('AtomicResultsResponse', $schemas);
        }

        $kernel->shutdown();
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theAtomicEnabledDocumentStillValidatesAgainstTheOas31MetaSchema(): void
    {
        $kernel = new OpenApiMultiServerTestKernel('test', false, false, true);
        $kernel->boot();

        // Decode the RAW wire JSON to the stdClass form (an empty Schema serializes as
        // `{}` only under the object decode; an assoc decode collapses it to `[]`).
        $response = $kernel->handle(Request::create('/docs.json', 'GET'), HttpKernelInterface::MAIN_REQUEST, true);
        self::assertSame(200, $response->getStatusCode());
        $document = \json_decode((string) $response->getContent(), false, 512, \JSON_THROW_ON_ERROR);

        $result = $this->metaValidator()->validate($document, self::OAS_SCHEMA_ID);
        self::assertTrue(
            $result->isValid(),
            'The atomic-enabled OpenAPI document is not a valid OpenAPI 3.1 document.',
        );

        $kernel->shutdown();
    }

    /**
     * The served atomic `data` schema must accept the **real** atomic-write wire — a
     * no-id create (the only valid create body for a type with `allowsClientId=false`,
     * such as the default server's `public-items`), a local-id (`lid`) create, and a
     * single to-one relationship identifier referenced by `lid` — while still rejecting
     * a body with no `type`. (Before the `<Type>AtomicWrite` component existed the
     * served schema referenced the id-requiring read shape, so it advertised that every
     * id-less create was invalid — the documented, asserted-passing example batch
     * included.)
     */
    #[Test]
    #[Group('spec:openapi')]
    public function theServedAtomicDataSchemaAcceptsTheRealWriteWire(): void
    {
        $kernel = new OpenApiMultiServerTestKernel('test', false, false, true);
        $kernel->boot();

        // Decode the raw wire document to stdClass (an empty Schema is `{}` only under
        // the object decode) and register it so internal $refs resolve.
        $response = $kernel->handle(Request::create('/docs.json', 'GET'), HttpKernelInterface::MAIN_REQUEST, true);
        self::assertSame(200, $response->getStatusCode());
        $document = \json_decode((string) $response->getContent(), false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $document);

        $validator = new Validator();
        $resolver = $validator->resolver();
        self::assertNotNull($resolver);
        $resolver->registerRaw($document, 'urn:atomic-conformance');
        $dataRef = 'urn:atomic-conformance#/components/schemas/AtomicOperation/properties/data';

        $isValid = static fn(mixed $instance): bool => $validator->validate(
            \json_decode((string) \json_encode($instance), false),
            $dataRef,
        )->isValid();

        // The headline cases the read-shape schema wrongly rejected.
        self::assertTrue($isValid(['type' => 'public-items', 'attributes' => ['name' => 'x']]), 'create (no id)');
        self::assertTrue($isValid(['type' => 'public-items', 'lid' => 'p1', 'attributes' => ['name' => 'x']]), 'create (lid)');
        self::assertTrue($isValid(['type' => 'public-items', 'lid' => 'p1']), 'to-one identifier (lid)');
        // Already-accepted shapes still validate.
        self::assertTrue($isValid(['type' => 'public-items', 'id' => '1', 'attributes' => ['name' => 'x']]), 'update (id)');
        self::assertTrue($isValid([['type' => 'public-items', 'lid' => 'p1']]), 'to-many array');
        self::assertTrue($isValid(null), 'to-one cleared (null)');
        // A body with no `type` is still rejected.
        self::assertFalse($isValid(['attributes' => ['name' => 'x']]), 'a type-less operation data must be rejected');

        $kernel->shutdown();
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theCombinedDocumentAdvertisesTheAtomicEndpointWhenEnabled(): void
    {
        $kernel = new OpenApiMultiServerTestKernel('test', false, true, true);
        $kernel->boot();

        $document = $this->document($kernel, '/docs.json');

        $paths = $this->asArray($document['paths'] ?? null);
        self::assertArrayHasKey('/operations', $paths);
        self::assertContains('Atomic Operations', $this->tagNames($document));

        $kernel->shutdown();
    }

    /**
     * @return array<string, mixed>
     */
    private function document(KernelInterface $kernel, string $path): array
    {
        $response = $kernel->handle(Request::create($path, 'GET'), HttpKernelInterface::MAIN_REQUEST, true);
        self::assertSame(200, $response->getStatusCode());

        $decoded = \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * The document-root tag names.
     *
     * @param array<string, mixed> $document
     *
     * @return list<mixed>
     */
    private function tagNames(array $document): array
    {
        $tags = $this->asArray($document['tags'] ?? null);

        return \array_values(\array_map(static fn(mixed $tag): mixed => \is_array($tag) ? ($tag['name'] ?? null) : null, $tags));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function asArray(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
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

        $base = __DIR__ . '/App/OpenApi/Fixture/';
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

    /**
     * Pops every error/exception handler a booted kernel pushed, back to the snapshot
     * taken in setUp, so the global handler stack is balanced for PHPUnit strict mode.
     */
    private function restoreHandlers(): void
    {
        while (true) {
            $current = \set_error_handler(static fn(): bool => false);
            \restore_error_handler();
            if ($current === $this->errorHandler) {
                break;
            }
            \restore_error_handler();
        }

        while (true) {
            $current = \set_exception_handler(null);
            \restore_exception_handler();
            if ($current === $this->exceptionHandler) {
                break;
            }
            \restore_exception_handler();
        }
    }
}
