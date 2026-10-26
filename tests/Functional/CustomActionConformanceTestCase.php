<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The dual-provider conformance contract for custom / non-CRUD actions (G13, bundle
 * ADR 0076, design §10): identical assertions run against the in-memory kernel
 * ({@see InMemoryCustomActionTest}) and the Doctrine-sqlite kernel
 * ({@see DoctrineCustomActionTest}), so a failure on one provider but not the other
 * localizes to that data layer.
 *
 * Every row of the §10 acceptance matrix is covered: a resource-scope `Document`
 * (here input-`None`) action returning the mount resource; a collection-scope action;
 * a Raw-input upload returning `204`; a custom `inputType`/`outputType` (a bespoke
 * command in, a bespoke document out); a per-action `security` deny → `403`; the
 * serving-gate deny → `403`; an unknown action → `404`; an entity-not-found
 * (resource scope) → `404`; a method mismatch → `405`; and route ordering (the action
 * path is not shadowed by `/{type}/{id}` nor a relationship route, and a normal
 * `/{type}/{id}` fetch still resolves alongside it).
 *
 * Two seeded `actionWidgets` (ids 1, 2) back the resource-scope cases; the firewall
 * resolves a Bearer token to a seeded `user` (`ROLE_USER`) / `admin` (`ROLE_ADMIN`).
 */
abstract class CustomActionConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    #[Group('spec:actions')]
    public function aResourceScopeActionResolvesTheMountEntityAndReturnsItsResource(): void
    {
        // The widget starts unpublished; the resource-scope `publish` action resolves
        // {id} to the entity, flips `published`, and renders the MOUNT resource.
        $response = $this->action('/actionWidgets/1/-actions/publish');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->dataOf($response);
        self::assertSame('actionWidgets', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertTrue($attributes['published'] ?? null);

        // The side-effect is persisted: a follow-up read sees it published.
        $fetched = $this->attributesOf($this->action('/actionWidgets/1', 'GET'));
        self::assertTrue($fetched['published'] ?? null);
    }

    #[Test]
    #[Group('spec:actions')]
    public function aCollectionScopeActionDispatchesWithoutAnEntity(): void
    {
        // No {id}: the collection-scope `import` action resolves no entity and renders
        // a meta-only document.
        $response = $this->action('/actionWidgets/-actions/import');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $meta = $this->decode($response)['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertSame(3, $meta['imported'] ?? null);
    }

    #[Test]
    #[Group('spec:actions')]
    public function aRawInputActionReadsTheUploadAndReturns204(): void
    {
        // The Raw upload action negotiates with a relaxed request content-type (a
        // multipart/blob body, NOT application/vnd.api+json), reads the raw body, and
        // returns a bodyless 204.
        $response = $this->rawUpload('/actionWidgets/1/-actions/artwork', 'BINARY-ARTWORK-BYTES');

        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('', (string) $response->getContent());

        // The uploaded bytes were attached to the entity.
        $fetched = $this->attributesOf($this->action('/actionWidgets/1', 'GET'));
        self::assertSame('BINARY-ARTWORK-BYTES', $fetched['uploadedArtwork'] ?? null);
    }

    #[Test]
    #[Group('spec:actions')]
    public function aDocumentActionAcceptsABespokeCommandAndReturnsABespokeDocument(): void
    {
        // The `rename` action's inputType (renameCommands) and outputType (receipts)
        // both differ from the mount type: a bespoke command rides in, a bespoke
        // document comes out — both valid JSON:API, decoupled from `actionWidgets`.
        $response = $this->action('/actionWidgets/2/-actions/rename', 'POST', [
            'data' => [
                'type' => 'renameCommands',
                'attributes' => ['newName' => 'Renamed widget'],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->dataOf($response);
        self::assertSame('receipts', $data['type'] ?? null);
        self::assertSame('receipt-Renamed widget', $data['id'] ?? null);
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('Renamed widget', $attributes['appliedName'] ?? null);

        // The command was applied to the resolved mount entity.
        $fetched = $this->attributesOf($this->action('/actionWidgets/2', 'GET'));
        self::assertSame('Renamed widget', $fetched['name'] ?? null);
    }

    #[Test]
    #[Group('spec:actions')]
    public function aPerActionSecurityExpressionDeniesWith403(): void
    {
        // The `archive` action carries security: is_granted('ROLE_ADMIN'). A ROLE_USER
        // request is denied at the BeforeActionEvent gate with a 403 — the handler
        // never runs.
        $response = $this->action('/actionWidgets/1/-actions/archive', 'POST', null, ['HTTP_AUTHORIZATION' => 'Bearer user']);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        $error = $errors[0] ?? null;
        self::assertIsArray($error);
        self::assertSame('ACCESS_DENIED', $error['code'] ?? null);
    }

    #[Test]
    #[Group('spec:actions')]
    public function aPerActionSecurityExpressionAdmitsAnAllowedUser(): void
    {
        // The same action with a ROLE_ADMIN user passes the gate and reaches the
        // handler (a 204).
        $response = $this->action('/actionWidgets/1/-actions/archive', 'POST', null, ['HTTP_AUTHORIZATION' => 'Bearer admin']);

        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:actions')]
    public function theServingGateDeniesAnActionWith403(): void
    {
        // The request-wide serving gate fires for an action (a CustomActionOperation
        // routes through Server::dispatch() unchanged); the witness subscriber denies
        // when X-Deny-Serving is present, mapping to a 403.
        $response = $this->action('/actionWidgets/1/-actions/publish', 'POST', null, [
            'HTTP_AUTHORIZATION' => 'Bearer admin',
            'HTTP_X_DENY_SERVING' => '1',
        ]);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:actions')]
    public function anUnknownActionReturns404(): void
    {
        // A path under -actions whose name matches no declared action: the route's
        // literal segment is the declared name, so an undeclared name simply does not
        // route. (A declared-but-method-mismatched route is the 405 case below.)
        $response = $this->action('/actionWidgets/1/-actions/nope');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    #[Group('spec:actions')]
    public function aResourceScopeActionOnAMissingEntityReturns404(): void
    {
        // The {id} resolves to no entity (via the mount type's DataProvider): a 404,
        // exactly as the CRUD read/update/delete arms.
        $response = $this->action('/actionWidgets/999/-actions/publish');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    #[Group('spec:actions')]
    public function aMethodMismatchReturns405(): void
    {
        // The `recalculate` action declares methods: ['PATCH'], so its route exists
        // only for PATCH. A POST to the same path matches the path but not the method
        // → Symfony 405s at routing, before any handler.
        $response = $this->action('/actionWidgets/-actions/recalculate', 'POST');

        self::assertSame(405, $response->getStatusCode());

        // The declared PATCH method does resolve.
        $patched = $this->action('/actionWidgets/-actions/recalculate', 'PATCH');
        self::assertSame(200, $patched->getStatusCode(), (string) $patched->getContent());
    }

    #[Test]
    #[Group('spec:actions')]
    public function anActionPathIsNotShadowedByTheResourceOrRelationshipRoutes(): void
    {
        // Route ordering (design §7): the action routes are emitted before the generic
        // /{type}/{id} and /{type}/{id}/{relationship} routes, so the literal -actions
        // segment is never captured as an {id} or a {relationship} name.
        //
        // A resource-scope action (4 segments) and a normal /{type}/{id} fetch (2
        // segments) BOTH resolve correctly side by side.
        $actionResponse = $this->action('/actionWidgets/1/-actions/publish');
        self::assertSame(200, $actionResponse->getStatusCode(), (string) $actionResponse->getContent());
        self::assertSame('actionWidgets', $this->dataOf($actionResponse)['type'] ?? null);

        $fetchResponse = $this->action('/actionWidgets/2', 'GET');
        self::assertSame(200, $fetchResponse->getStatusCode(), (string) $fetchResponse->getContent());
        $fetched = $this->dataOf($fetchResponse);
        self::assertSame('actionWidgets', $fetched['type'] ?? null);
        self::assertSame('2', $fetched['id'] ?? null);

        // The collection-scope action (3 segments) is not shadowed by the resource
        // route either: -actions never lands as an {id}.
        $collectionAction = $this->action('/actionWidgets/-actions/import');
        self::assertSame(200, $collectionAction->getStatusCode(), (string) $collectionAction->getContent());
    }

    #[Test]
    #[Group('spec:actions')]
    public function aNonActionMethodOnACollectionScopeActionPathIsNotShadowedByTheRelatedRoute(): void
    {
        // Regression guard for the collection-scope shadow (design §7's "the literal
        // `-actions` is never captured as an {id}" guarantee). A collection-scope
        // action path `/{type}/-actions/{name}` is three segments — structurally
        // identical to the generic related route `GET /{type}/{id}/{relationship}`.
        // Emitting the action route first only shields the action's OWN declared
        // methods; for any OTHER method the related route would otherwise match with
        // {id} = the literal `-actions` and silently dispatch a related-resource read
        // — bypassing the action's authz/dispatch entirely. The {id} requirement
        // excludes the reserved segment, so the path 405s (the route exists, the
        // method does not) rather than 404ing as a related read on a parent `-actions`.
        //
        // `recalculate` declares methods: ['PATCH']; `import` declares ['POST'].
        // A GET to either matches no declared method, so the router 405s.
        $getRecalculate = $this->action('/actionWidgets/-actions/recalculate', 'GET');
        self::assertSame(405, $getRecalculate->getStatusCode(), (string) $getRecalculate->getContent());

        $getImport = $this->action('/actionWidgets/-actions/import', 'GET');
        self::assertSame(405, $getImport->getStatusCode(), (string) $getImport->getContent());

        // The declared methods still resolve to their actions (not the related route).
        $patch = $this->action('/actionWidgets/-actions/recalculate', 'PATCH');
        self::assertSame(200, $patch->getStatusCode(), (string) $patch->getContent());

        $post = $this->action('/actionWidgets/-actions/import');
        self::assertSame(200, $post->getStatusCode(), (string) $post->getContent());
    }

    #[Test]
    #[Group('spec:actions')]
    public function anUngatedAsLinkActionRendersAsAResourceLink(): void
    {
        // The `pin` action declares asLink: true with NO security, so its `links.pin`
        // member renders on every rendered actionWidgets resource and resolves to the
        // action's own route URL (bundle ADR 0091).
        $links = $this->linksOf($this->action('/actionWidgets/1', 'GET'));

        self::assertArrayHasKey('pin', $links);
        self::assertStringEndsWith('/actionWidgets/1/-actions/pin', $this->href($links['pin']));
    }

    #[Test]
    #[Group('spec:actions')]
    public function aSecurityGatedAsLinkActionRendersOnlyForAnAdmittedRequester(): void
    {
        // The `archive` action declares asLink: true AND security: ROLE_ADMIN. The link
        // renders ONLY for a requester who would pass the same gate the BeforeActionEvent
        // uses — present for `admin`, absent for `user` (bundle ADR 0091).
        $adminLinks = $this->linksOf($this->action('/actionWidgets/1', 'GET', null, ['HTTP_AUTHORIZATION' => 'Bearer admin']));
        self::assertArrayHasKey('archive', $adminLinks);
        self::assertStringEndsWith('/actionWidgets/1/-actions/archive', $this->href($adminLinks['archive']));

        $userLinks = $this->linksOf($this->action('/actionWidgets/1', 'GET', null, ['HTTP_AUTHORIZATION' => 'Bearer user']));
        self::assertArrayNotHasKey('archive', $userLinks);

        // The ungated link is unaffected by the security gate — it shows for `user` too.
        self::assertArrayHasKey('pin', $userLinks);
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function anAcceptedMediaTypeIsMatchedAndReportedToTheHandler(): void
    {
        foreach ([
            ['text/csv; charset=utf-8', "a,b\n", 'text/csv'],
            ['image/png', 'PNG-BYTES', 'image/png'],
            ['multipart/form-data; boundary=x', "--x--\r\n", 'multipart/form-data'],
            ['application/json', '["a","b"]', 'application/json'],
        ] as [$contentType, $body, $matched]) {
            $response = $this->handleRaw('/actionWidgets/-actions/ingest', $body, $contentType, extraServer: ['HTTP_AUTHORIZATION' => 'Bearer user']);

            self::assertSame(200, $response->getStatusCode(), $contentType . ': ' . $response->getContent());
            self::assertSame(['mediaType' => $matched, 'document' => false, 'bytes' => \strlen($body)], $this->decode($response)['meta'] ?? null);
        }
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function aJsonApiEntryReadsTheBodyAsADocument(): void
    {
        $response = $this->action('/actionWidgets/-actions/ingest', 'POST', ['data' => ['type' => 'actionWidgets', 'attributes' => ['name' => 'Ingested']]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $meta = $this->decode($response)['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertSame('application/vnd.api+json', $meta['mediaType'] ?? null);
        self::assertTrue($meta['document'] ?? null);
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function aMediaTypeTheActionDoesNotAcceptIs415(): void
    {
        foreach (['text/plain', 'application/x-www-form-urlencoded', 'application/xml'] as $contentType) {
            $response = $this->handleRaw('/actionWidgets/-actions/ingest', 'x=1', $contentType, extraServer: ['HTTP_AUTHORIZATION' => 'Bearer user']);

            self::assertSame(415, $response->getStatusCode(), $contentType);
        }
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function aBodylessPostWithTheJsonApiTypePassesAnyAction(): void
    {
        $response = $this->action('/actionWidgets/-actions/ingest');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['mediaType' => null, 'document' => false, 'bytes' => 0], $this->decode($response)['meta'] ?? null);
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function aPostWithNoContentTypeIs415(): void
    {
        foreach (['/actionWidgets/-actions/ingest', '/actionWidgets/-actions/import', '/actionWidgets/1/-actions/artwork'] as $path) {
            $response = $this->handleRaw($path, '', '', extraServer: ['HTTP_AUTHORIZATION' => 'Bearer user']);

            self::assertSame(415, $response->getStatusCode(), $path);
        }
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function aRawActionWithoutAcceptsRefusesTheFormTypes(): void
    {
        foreach (['text/plain', 'multipart/form-data; boundary=x', 'application/x-www-form-urlencoded'] as $contentType) {
            $response = $this->handleRaw('/actionWidgets/1/-actions/artwork', 'x=1', $contentType, extraServer: ['HTTP_AUTHORIZATION' => 'Bearer user']);

            self::assertSame(415, $response->getStatusCode(), $contentType);
        }
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function aNoneActionPostMustSendTheJsonApiType(): void
    {
        $response = $this->handleRaw('/actionWidgets/-actions/import', '', 'text/plain', extraServer: ['HTTP_AUTHORIZATION' => 'Bearer user']);

        self::assertSame(415, $response->getStatusCode());
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function aCrudWriteMustSendTheJsonApiType(): void
    {
        $document = '{"data":{"type":"actionWidgets","id":"1","attributes":{}}}';
        foreach ([['POST', '/actionWidgets', 'text/plain'], ['POST', '/actionWidgets', ''], ['PATCH', '/actionWidgets/1', 'application/json'], ['PATCH', '/actionWidgets/1', '']] as [$method, $path, $contentType]) {
            $response = $this->handleRaw($path, $document, $contentType, $method, ['HTTP_AUTHORIZATION' => 'Bearer user']);

            self::assertSame(415, $response->getStatusCode(), $method . ' ' . $contentType);
        }

        // A bodyless DELETE needs no Content-Type.
        $delete = $this->handleRaw('/actionWidgets/2', '', '', 'DELETE', ['HTTP_AUTHORIZATION' => 'Bearer user']);
        self::assertNotSame(415, $delete->getStatusCode());
    }

    #[Test]
    #[Group('spec:openapi')]
    public function eachAcceptedMediaTypeIsARequestBodyEntry(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $document = $factory->forServer()->toArray();

        $operation = ['paths', '/actionWidgets/-actions/ingest', 'post'];
        $content = self::at($document, ...$operation, ...['requestBody', 'content']);
        self::assertIsArray($content);
        self::assertSame(['text/csv', 'image/*', 'multipart/form-data', 'application/vnd.api+json', 'application/json'], \array_keys($content));
        self::assertSame(['type' => 'string', 'format' => 'binary'], self::at($content, 'image/*', 'schema'));
        self::assertSame(['type' => 'object'], self::at($content, 'multipart/form-data', 'schema'));
        self::assertSame(['type' => 'array', 'items' => ['type' => 'string']], self::at($content, 'application/json', 'schema'));
        self::assertNotNull(self::at($document, ...$operation, ...['responses', '415']));

        // An action without accepts keeps its input mode's body.
        $artwork = self::at($document, 'paths', '/actionWidgets/{id}/-actions/artwork', 'post', 'requestBody', 'content');
        self::assertIsArray($artwork);
        self::assertSame(['application/octet-stream'], \array_keys($artwork));
    }

    private static function at(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $value = \is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
    }

    /**
     * Issues an action request: an authenticated (default `user`) JSON:API request.
     * A JSON `$body` array is sent as an `application/vnd.api+json` document (Document
     * mode); a `null` body sends none (None mode / a plain fetch).
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $extraServer
     */
    protected function action(string $path, string $method = 'POST', ?array $body = null, array $extraServer = []): Response
    {
        // $extraServer wins on a duplicate key (so a per-test `Bearer admin` overrides
        // the `Bearer user` default), the opposite of PHP's array-union precedence.
        return $this->handle($path, $method, $body, $extraServer + ['HTTP_AUTHORIZATION' => 'Bearer user']);
    }

    /**
     * Issues a Raw-input upload: a non-JSON:API blob body (here `application/octet-stream`),
     * with a JSON:API `Accept` so response negotiation still resolves. The request
     * content-type is deliberately NOT `application/vnd.api+json` — the action's Raw
     * input relaxes the request content-type assertion.
     */
    protected function rawUpload(string $path, string $body): Response
    {
        return $this->handleRaw($path, $body, extraServer: ['HTTP_AUTHORIZATION' => 'Bearer user']);
    }

    /**
     * The decoded document's primary `data` object, narrowed for offset access.
     *
     * @return array<string, mixed>
     */
    private function dataOf(Response $response): array
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * The decoded document's `data.attributes`, narrowed for offset access.
     *
     * @return array<string, mixed>
     */
    private function attributesOf(Response $response): array
    {
        $attributes = $this->dataOf($response)['attributes'] ?? null;
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }

    /**
     * The decoded document's primary `data.links`, narrowed for offset access.
     *
     * @return array<string, mixed>
     */
    protected function linksOf(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $links = $this->dataOf($response)['links'] ?? null;
        self::assertIsArray($links);

        /** @var array<string, mixed> $links */
        return $links;
    }

    /**
     * The href of a JSON:API link member, which serializes either as a bare URL string
     * or as a `{href, meta}` link object.
     */
    protected function href(mixed $link): string
    {
        if (\is_string($link)) {
            return $link;
        }

        self::assertIsArray($link);
        $href = $link['href'] ?? null;
        self::assertIsString($href);

        return $href;
    }
}
