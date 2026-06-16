<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Testing;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Testing\JsonApiDocument;
use haddowg\JsonApi\Testing\JsonApiErrors;
use haddowg\JsonApi\Testing\ResponseMeta;
use haddowg\JsonApiBundle\Server\ServerProvider;
use PHPUnit\Framework\Assert;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\CookieJar;
use Symfony\Component\BrowserKit\History;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A shipped, supported JSON:API test client: a {@see KernelBrowser} that knows the
 * JSON:API media type and bridges an HttpFoundation {@see Response} to core's fluent
 * assertion families ({@see JsonApiDocument} / {@see JsonApiErrors}).
 *
 * Construct it directly from the booted kernel — `new JsonApiBrowser($kernel)` — so
 * it works under a plain {@see \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase}
 * boot without `WebTestCase`. (A standard `WebTestCase` gets one for free via
 * {@see InteractsWithJsonApi}, which swaps the `test.client` service for this class.)
 * It:
 *
 *  - **disables kernel reboot in the constructor.** A real {@see KernelBrowser}
 *    reboots the kernel between requests, which would wipe an in-memory SQLite seed
 *    that lives with the kernel's connection; `disableReboot()` keeps the one booted
 *    kernel across requests so a write-then-read in a single test sees the write.
 *  - **negotiates content automatically.** Every request defaults
 *    `Accept: application/vnd.api+json`; a write ({@see post()}/{@see patch()}) sets
 *    `Content-Type: application/vnd.api+json` and JSON-encodes a passed document array.
 *  - **preserves the kernel.exception path.** Requests route through
 *    `kernel->handle(catch: true)`, so the bundle's `ExceptionListener` renders a
 *    `400`/`404`/`422` as a JSON:API **error document** (not a thrown exception),
 *    which {@see getErrors()} then asserts over.
 *  - **keeps the PHPUnit handler stack balanced.** Handling installs Symfony's
 *    error/exception handlers; the browser snapshots them on construction and pops
 *    back to that snapshot after each request, so PHPUnit's strict-mode handler check
 *    stays balanced (mirroring the base test case).
 *
 * **Authentication.** {@see actingAs()} authenticates **statelessly** as a seeded
 * user by setting an `Authorization: Bearer <token>` header on every subsequent
 * request — the most common API auth scenario. The default scheme maps the user
 * identifier straight to the token ({@see tokenFor()}); a real app maps an opaque
 * token to a user, so a consumer with a different stateless scheme overrides one of
 * the two protected seams ({@see authenticateAs()} / {@see tokenFor()}).
 *
 * **Extension points.** The class is non-`final` and exposes its behaviour as
 * protected, overridable seams so a consumer can subclass and customise without
 * copy-paste:
 *
 *  - {@see authenticateAs()} / {@see tokenFor()} — the stateless auth scheme.
 *  - {@see defaultRequestServer()} — the per-request `$_SERVER` defaults (the
 *    `Accept`/`Content-Type` negotiation).
 *  - {@see documentFor()} / {@see errorsFor()} — how a response becomes a core
 *    {@see JsonApiDocument} / {@see JsonApiErrors}.
 */
class JsonApiBrowser extends KernelBrowser
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @var callable|null the error handler active when this browser was constructed
     */
    private $errorHandlerSnapshot;

    /**
     * @var callable|null the exception handler active when this browser was constructed
     */
    private $exceptionHandlerSnapshot;

    /**
     * The signature mirrors the parent {@see KernelBrowser} (and the `test.client`
     * service definition) so {@see InteractsWithJsonApi} can swap this class in as a
     * drop-in client. A standalone `new JsonApiBrowser($kernel)` uses the defaults.
     *
     * @param array<string, mixed> $server default `$_SERVER` entries for every request
     */
    public function __construct(KernelInterface $kernel, array $server = [], ?History $history = null, ?CookieJar $cookieJar = null)
    {
        // Snapshot the active error/exception handlers before any request installs
        // Symfony's, so each request can pop back to this baseline (PHPUnit strict).
        $this->errorHandlerSnapshot = \set_error_handler(null);
        \restore_error_handler();
        $this->exceptionHandlerSnapshot = \set_exception_handler(null);
        \restore_exception_handler();

        parent::__construct($kernel, $server, $history, $cookieJar);

        // The #1 trap: a KernelBrowser reboots the kernel between requests by
        // default, which would wipe an in-memory SQLite seed bound to the kernel's
        // connection. Keep the one booted kernel across requests.
        $this->disableReboot();
    }

    // --- request helpers (auto content-negotiation) --------------------------

    /**
     * A JSON:API `GET`, defaulting the `Accept` header.
     *
     * @param array<string, mixed> $server extra `$_SERVER` entries for this request
     */
    public function get(string $uri, array $server = []): self
    {
        $this->jsonApiRequest('GET', $uri, null, $server);

        return $this;
    }

    /**
     * A JSON:API `POST`, JSON-encoding `$document` with the write media type.
     *
     * @param array<string, mixed>|null $document a JSON:API document to send as the body
     * @param array<string, mixed>      $server   extra `$_SERVER` entries for this request
     */
    public function post(string $uri, ?array $document = null, array $server = []): self
    {
        $this->jsonApiRequest('POST', $uri, $document, $server);

        return $this;
    }

    /**
     * A JSON:API `PATCH`, JSON-encoding `$document` with the write media type.
     *
     * @param array<string, mixed>|null $document a JSON:API document to send as the body
     * @param array<string, mixed>      $server   extra `$_SERVER` entries for this request
     */
    public function patch(string $uri, ?array $document = null, array $server = []): self
    {
        $this->jsonApiRequest('PATCH', $uri, $document, $server);

        return $this;
    }

    /**
     * A JSON:API `DELETE`. A `$document` (e.g. relationship-remove linkage) is
     * JSON-encoded with the write media type when supplied.
     *
     * @param array<string, mixed>|null $document an optional JSON:API document body
     * @param array<string, mixed>      $server   extra `$_SERVER` entries for this request
     */
    public function delete(string $uri, ?array $document = null, array $server = []): self
    {
        $this->jsonApiRequest('DELETE', $uri, $document, $server);

        return $this;
    }

    /**
     * Authenticates **statelessly** as `$user` for every subsequent request — the
     * most common API auth scenario. The user is identified by their
     * {@see UserInterface::getUserIdentifier()} (or the raw string), which
     * {@see authenticateAs()} carries as a Bearer access token; the firewall
     * under test resolves that token back to the seeded user.
     *
     * No session, no `loginUser()`, no HTTP-Basic. A consumer whose stateless scheme
     * differs (an opaque token, a signed JWT, a different header) overrides
     * {@see authenticateAs()} or {@see tokenFor()} — one method, no copy-paste.
     */
    public function actingAs(UserInterface|string $user): static
    {
        $identifier = $user instanceof UserInterface ? $user->getUserIdentifier() : $user;
        $this->authenticateAs($identifier);

        return $this;
    }

    /**
     * The stateless auth seam: how an authenticated identifier is carried on every
     * subsequent request. The default sets an `Authorization: Bearer <token>` header
     * ({@see tokenFor()} mints the token).
     *
     * Override this to authenticate over a different stateless scheme (e.g. a custom
     * header or an `X-Api-Key`).
     */
    protected function authenticateAs(string $identifier): void
    {
        $this->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $this->tokenFor($identifier));
    }

    /**
     * Mints the bearer token for an authenticated user identifier. The default is
     * the identity function: the token **is** the identifier, which the test app's
     * `AccessTokenHandler` resolves straight back to the seeded user.
     *
     * A real application maps an opaque token to a user (a lookup against a token
     * store); override this to mint the token your app's token handler expects.
     */
    protected function tokenFor(string $identifier): string
    {
        return $identifier;
    }

    // --- fluent assertions over the last response ----------------------------

    public function assertStatus(int $status): self
    {
        $this->getDocument()->assertStatus($status);

        return $this;
    }

    public function assertContentType(string $expected = self::MEDIA_TYPE): self
    {
        $this->getDocument()->assertContentType($expected);

        return $this;
    }

    public function assertHeader(string $name, ?string $expected = null): self
    {
        $this->getDocument()->assertHeader($name, $expected);

        return $this;
    }

    /**
     * Asserts a `201 Created`: the status, the JSON:API content type, and a
     * `Location` header (#11c).
     */
    public function assertCreated(): self
    {
        $document = $this->getDocument();
        $document->assertStatus(Response::HTTP_CREATED);
        $document->assertContentType();
        $document->assertHeader('Location');

        return $this;
    }

    /**
     * Asserts a `204 No Content`: the status and an empty body.
     */
    public function assertNoContent(): self
    {
        $this->getDocument()->assertStatus(Response::HTTP_NO_CONTENT);
        Assert::assertSame('', $this->response()->getContent(), 'A 204 response must carry an empty body.');

        return $this;
    }

    /**
     * Asserts a `200 OK` single-resource read (status + content type), returning the
     * document for further single-resource assertions ({@see JsonApiDocument::assertHasType()}, …).
     */
    public function assertFetchedOne(): JsonApiDocument
    {
        $document = $this->getDocument();
        $document->assertStatus(Response::HTTP_OK);
        $document->assertContentType();

        return $document;
    }

    /**
     * Asserts a `200 OK` and that the primary `data` is a collection, returning the
     * document for further collection assertions.
     */
    public function assertFetchedMany(): JsonApiDocument
    {
        $document = $this->getDocument();
        $document->assertStatus(Response::HTTP_OK);
        $document->assertContentType();
        $document->assertFetchedMany();

        return $document;
    }

    /**
     * The `?sort` witness: asserts the collection carries exactly `$idsInOrder` **in
     * that order** (status + content type asserted first).
     *
     * @param list<string> $idsInOrder
     */
    public function assertFetchedManyInOrder(array $idsInOrder, ?string $type = null): self
    {
        $this->assertFetchedMany()->assertFetchedManyInOrder($idsInOrder, $type);

        return $this;
    }

    /**
     * Whole-member exact compare of the single primary resource object — catches a
     * leaked/extra attribute or relationship (#11a).
     *
     * @param array<string, mixed> $expected
     */
    public function assertFetchedOneExact(array $expected): self
    {
        $this->assertFetchedOne()->assertFetchedOneExact($expected);

        return $this;
    }

    /**
     * Order-sensitive exact compare of the collection members.
     *
     * @param list<array<string, mixed>> $expected
     */
    public function assertFetchedManyExact(array $expected): self
    {
        $this->assertFetchedMany()->assertFetchedManyExact($expected);

        return $this;
    }

    /**
     * Asserts at least one error object exactly matches `$error`.
     *
     * @param array<string, mixed> $error
     */
    public function assertHasExactError(array $error): self
    {
        $this->getErrors()->assertHasExactError($error);

        return $this;
    }

    /**
     * Asserts the error list is exactly `$errors`, order-sensitive.
     *
     * @param list<array<string, mixed>> $errors
     */
    public function assertErrorsExact(array $errors): self
    {
        $this->getErrors()->assertErrorsExact($errors);

        return $this;
    }

    public function assertHasError(?string $status = null, ?string $pointer = null, ?string $code = null): self
    {
        $this->getErrors()->assertHasError($status, $pointer, $code);

        return $this;
    }

    public function assertNoData(): self
    {
        $this->getDocument()->assertNoData();

        return $this;
    }

    public function assertNoMeta(): self
    {
        $this->getDocument()->assertNoMeta();

        return $this;
    }

    public function assertNoLink(?string $rel = null): self
    {
        $this->getDocument()->assertNoLink($rel);

        return $this;
    }

    // --- bridges to the core families ----------------------------------------

    /**
     * The last response as a core {@see JsonApiDocument}, carrying the HTTP status +
     * header map as a {@see ResponseMeta} so the envelope assertions work alongside
     * the body assertions.
     */
    public function getDocument(): JsonApiDocument
    {
        return $this->documentFor($this->response());
    }

    /**
     * The last response as a core {@see JsonApiErrors}, carrying the same envelope.
     */
    public function getErrors(): JsonApiErrors
    {
        return $this->errorsFor($this->response());
    }

    /**
     * The document-construction seam: how a response becomes a core
     * {@see JsonApiDocument}. Override to wrap a response that needs decoding (e.g. a
     * gzipped or enveloped body) before the assertions run.
     */
    protected function documentFor(Response $response): JsonApiDocument
    {
        return JsonApiDocument::of(
            (string) $response->getContent(),
            meta: $this->responseMeta($response),
        );
    }

    /**
     * The errors-construction seam, the twin of {@see documentFor()}.
     */
    protected function errorsFor(Response $response): JsonApiErrors
    {
        return JsonApiErrors::of(
            (string) $response->getContent(),
            meta: $this->responseMeta($response),
        );
    }

    /**
     * Derives the **expected** JSON:API resource object for `$entity` by running it
     * through its own serializer — resolved from the container's JSON:API server, the
     * same way the read endpoint resolves it — so it can be fed to
     * {@see assertFetchedOneExact()} without hand-writing the `{type, id, attributes,
     * relationships}` shape (#47). A model-aware convenience the framework-agnostic
     * core could not provide.
     *
     * @return array<string, mixed> the expected primary resource object
     */
    public function expectResource(object $entity, ?string $serverName = null): array
    {
        $container = $this->getContainer();

        $servers = $container->get(ServerProvider::class);
        \assert($servers instanceof ServerProvider);
        $server = $servers->get($serverName);

        $psrHttpFactory = $container->get(PsrHttpFactory::class);
        \assert($psrHttpFactory instanceof PsrHttpFactory);
        $psrRequest = $psrHttpFactory->createRequest(
            Request::create('/', 'GET', server: ['HTTP_ACCEPT' => self::MEDIA_TYPE]),
        );

        // Resolve the entity's type by matching it against each registered
        // serializer: the serializer that serializes this object reports its own
        // registered type from getType(). A foreign serializer that errors on the
        // object is skipped, so only the genuine owner matches.
        $type = null;
        foreach ($server->resources()->types() as $candidate) {
            if (!$server->hasSerializerFor($candidate)) {
                continue;
            }
            try {
                if ($server->serializerFor($candidate)->getType($entity) === $candidate) {
                    $type = $candidate;

                    break;
                }
            } catch (\Throwable) {
                // Not this serializer's object — keep looking.
            }
        }
        Assert::assertNotNull(
            $type,
            \sprintf('No registered JSON:API serializer recognises "%s".', $entity::class),
        );

        $serializer = $server->serializerFor($type);

        $document = (string) DataResponse::fromResource($entity, $serializer)
            ->toPsrResponse($server, $psrRequest)
            ->getBody();

        $decoded = \json_decode($document, true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));
        $data = $decoded['data'] ?? null;
        \assert(\is_array($data));

        /** @var array<string, mixed> $data */
        return $data;
    }

    // --- request lifecycle ---------------------------------------------------

    /**
     * The per-request `$_SERVER` defaults seam: the JSON:API content negotiation
     * headers applied to every request. `$hasBody` is true for a write, so a
     * `Content-Type` is added only when a body is sent. Override to negotiate a
     * different media-type profile or to add standing headers.
     *
     * @return array<string, mixed>
     */
    protected function defaultRequestServer(bool $hasBody): array
    {
        $server = ['HTTP_ACCEPT' => self::MEDIA_TYPE];
        if ($hasBody) {
            $server['CONTENT_TYPE'] = self::MEDIA_TYPE;
        }

        return $server;
    }

    /**
     * Issues the request with the JSON:API media headers and a JSON-encoded body.
     *
     * @param array<string, mixed>|null $document
     * @param array<string, mixed>      $server
     */
    private function jsonApiRequest(string $method, string $uri, ?array $document, array $server): void
    {
        $server += $this->defaultRequestServer($document !== null);
        $content = $document !== null
            ? \json_encode($document, \JSON_THROW_ON_ERROR)
            : null;

        $this->request($method, $uri, server: $server, content: $content);
    }

    /**
     * Restores the global error/exception handlers the kernel installed while
     * handling, so PHPUnit's strict-mode handler check sees a balanced stack.
     */
    protected function doRequest(object $request): Response
    {
        try {
            return parent::doRequest($request);
        } finally {
            $this->restoreHandlers();
        }
    }

    /**
     * The last HttpFoundation response (typed for the bridges/assertions).
     */
    private function response(): Response
    {
        $response = $this->getResponse();
        \assert($response instanceof Response);

        return $response;
    }

    /**
     * The plain-scalar response envelope (status + a flattened header map) the core
     * families read.
     */
    private function responseMeta(Response $response): ResponseMeta
    {
        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            $headers[$name] = \implode(', ', $values);
        }

        return new ResponseMeta($response->getStatusCode(), $headers);
    }

    /**
     * Pops every error/exception handler the kernel pushed back to the snapshot
     * taken in the constructor, so the global handler stack is balanced.
     */
    private function restoreHandlers(): void
    {
        while (true) {
            $current = \set_error_handler(static fn(): bool => false);
            \restore_error_handler();
            if ($current === $this->errorHandlerSnapshot) {
                break;
            }
            \restore_error_handler();
        }

        while (true) {
            $current = \set_exception_handler(null);
            \restore_exception_handler();
            if ($current === $this->exceptionHandlerSnapshot) {
                break;
            }
            \restore_exception_handler();
        }
    }
}
