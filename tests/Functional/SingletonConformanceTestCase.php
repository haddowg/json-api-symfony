<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Singleton\InboxResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Singleton\MarkInboxRead;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Singleton types, against the Doctrine ({@see DoctrineSingletonTest}) and in-memory
 * ({@see InMemorySingletonTest}) kernels.
 *
 * `inboxes` is served at `/inbox`. Inbox 1 is ada's and holds items 1 and 2, pinning item
 * 2; inbox 2 is grace's and holds item 3. hopper has no inbox, ghost's names a row that
 * doesn't exist, and mallory's names grace's, which the access scope hides from her. The
 * `preferences` singleton is composed from a bare serializer.
 */
abstract class SingletonConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string ATOMIC = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';

    protected function afterBoot(): void
    {
        InboxResource::$lastContext = null;
        MarkInboxRead::$committed = [];
    }

    #[Test]
    public function theCallersResourceIsFetchedAtTheTypesPath(): void
    {
        $response = $this->as('ada', '/inbox');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('inboxes', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);
        self::assertSame(['title' => 'Ada', 'unread' => 3], $data['attributes'] ?? null);
        self::assertSame('https://example.test/inbox', self::at($data, 'links', 'self', 'href'));
        self::assertSame('https://example.test/inbox/relationships/items', self::at($data, 'relationships', 'items', 'links', 'self', 'href'));
        self::assertSame('https://example.test/inbox/items', self::at($data, 'relationships', 'items', 'links', 'related', 'href'));
        self::assertSame('https://example.test/inbox/-actions/mark-read', self::at($data, 'links', 'mark-read', 'href'));

        $grace = $this->decode($this->as('grace', '/inbox'));
        self::assertSame('2', self::at($grace, 'data', 'id'));
    }

    #[Test]
    public function theTypeResolvesTheIdFromTheUserAndTheRequest(): void
    {
        $this->as('ada', '/inbox');

        $context = InboxResource::$lastContext;
        self::assertNotNull($context);
        self::assertSame('inboxes', $context->type);
        self::assertInstanceOf(ServerRequestInterface::class, $context->request);
        $user = $context->attribute('user');
        self::assertInstanceOf(UserInterface::class, $user);
        self::assertSame('ada', $user->getUserIdentifier());
        $request = $context->attribute('request');
        self::assertInstanceOf(Request::class, $request);
        self::assertSame('/inbox', $request->getPathInfo());
    }

    #[Test]
    public function aCallerTheTypeNamesNoResourceForIsA404(): void
    {
        self::assertSame(Response::HTTP_NOT_FOUND, $this->as('hopper', '/inbox')->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->as('hopper', '/inbox/items')->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->as('hopper', '/inbox/relationships/items')->getStatusCode());
    }

    #[Test]
    public function anIdNamingNoRowIsA404(): void
    {
        $response = $this->as('ghost', '/inbox');

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('RESOURCE_NOT_FOUND', self::at($this->decode($response), 'errors', '0', 'code'));
    }

    #[Test]
    public function theAccessScopeHidesARowTheTypeResolvedForTheWrongCaller(): void
    {
        self::assertSame(Response::HTTP_NOT_FOUND, $this->as('mallory', '/inbox')->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->as('mallory', '/inbox', 'PATCH', $this->rename('2', 'Taken'))->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->as('mallory', '/inbox/-actions/mark-read', 'POST')->getStatusCode());
        self::assertSame('Grace', self::at($this->decode($this->as('grace', '/inbox')), 'data', 'attributes', 'title'));
    }

    #[Test]
    public function theFirewallGuardsTheSingletonsPath(): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->handle('/inbox')->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->handle('/inbox-items/1')->getStatusCode());
    }

    #[Test]
    public function anUpdateRunsOnTheCallersResource(): void
    {
        $response = $this->as('ada', '/inbox', 'PATCH', $this->rename('1', 'Renamed'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('Renamed', self::at($this->decode($response), 'data', 'attributes', 'title'));
        self::assertSame('Renamed', self::at($this->decode($this->as('ada', '/inbox')), 'data', 'attributes', 'title'));
    }

    #[Test]
    public function anUpdateBodyNamingAnotherIdIsA409(): void
    {
        $response = $this->as('ada', '/inbox', 'PATCH', $this->rename('2', 'Hijacked'));

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('RESOURCE_ID_CONFLICT', self::at($this->decode($response), 'errors', '0', 'code'));
        self::assertSame('Grace', self::at($this->decode($this->as('grace', '/inbox')), 'data', 'attributes', 'title'));
    }

    #[Test]
    public function aSingletonServesNoCreateDeleteOrIdPath(): void
    {
        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $this->as('ada', '/inbox', 'POST', ['data' => ['type' => 'inboxes', 'attributes' => ['title' => 'New']]])->getStatusCode());
        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $this->as('ada', '/inbox', 'DELETE')->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->as('ada', '/inbox/1')->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->as('ada', '/inboxes')->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->as('ada', '/inboxes/1')->getStatusCode());
    }

    #[Test]
    public function theRouterEmitsOnlyTheSingletonsRoutes(): void
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $routes = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            if ($route->getDefault('_jsonapi_type') === 'inboxes') {
                $routes[$name] = \implode(',', $route->getMethods()) . ' ' . $route->getPath();
            }
        }
        \ksort($routes);

        self::assertSame([
            'jsonapi.inboxes.action.resource.mark-read' => 'POST /inbox/-actions/mark-read',
            'jsonapi.inboxes.related.show' => 'GET /inbox/{relationship}',
            'jsonapi.inboxes.relationship.add' => 'POST /inbox/relationships/{relationship}',
            'jsonapi.inboxes.relationship.remove' => 'DELETE /inbox/relationships/{relationship}',
            'jsonapi.inboxes.relationship.show' => 'GET /inbox/relationships/{relationship}',
            'jsonapi.inboxes.relationship.update' => 'PATCH /inbox/relationships/{relationship}',
            'jsonapi.inboxes.show' => 'GET /inbox',
            'jsonapi.inboxes.update' => 'PATCH /inbox',
        ], $routes);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theRelationshipEndpointsHangOffTheTypesPath(): void
    {
        $linkage = $this->decode($this->as('ada', '/inbox/relationships/items'));
        self::assertSame([['type' => 'inbox-items', 'id' => '1'], ['type' => 'inbox-items', 'id' => '2']], $linkage['data'] ?? null);
        self::assertSame('https://example.test/inbox/relationships/items', self::at($linkage, 'links', 'self', 'href'));
        self::assertSame('https://example.test/inbox/items', self::at($linkage, 'links', 'related', 'href'));

        $related = $this->decode($this->as('ada', '/inbox/items'));
        self::assertSame(['1', '2'], self::ids($related['data'] ?? null));

        $pinned = $this->decode($this->as('ada', '/inbox/pinned'));
        self::assertSame('2', self::at($pinned, 'data', 'id'));

        $grace = $this->decode($this->as('grace', '/inbox/items'));
        self::assertSame(['3'], self::ids($grace['data'] ?? null));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aRelationshipOfTheCallersResourceIsReplaced(): void
    {
        $response = $this->as('ada', '/inbox/relationships/pinned', 'PATCH', ['data' => ['type' => 'inbox-items', 'id' => '1']]);

        self::assertLessThan(300, $response->getStatusCode());
        self::assertSame('1', self::at($this->decode($this->as('ada', '/inbox/pinned')), 'data', 'id'));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anotherTypeIncludesTheSingletonByItsId(): void
    {
        $document = $this->decode($this->as('ada', '/inbox-items/1?include=inbox'));

        self::assertSame(['type' => 'inboxes', 'id' => '1'], self::at($document, 'data', 'relationships', 'inbox', 'data'));
        $included = $document['included'] ?? null;
        self::assertIsArray($included);
        self::assertCount(1, $included);
        self::assertSame('inboxes', self::at($included, '0', 'type'));
        self::assertSame('1', self::at($included, '0', 'id'));
        self::assertSame('https://example.test/inbox', self::at($included, '0', 'links', 'self', 'href'));
    }

    #[Test]
    public function theSingletonIncludesItsRelations(): void
    {
        $document = $this->decode($this->as('ada', '/inbox?include=items'));

        $included = $document['included'] ?? null;
        self::assertIsArray($included);
        self::assertSame(['1', '2'], self::ids($included));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function anotherTypesRelatedEndpointRendersTheSingleton(): void
    {
        $document = $this->decode($this->as('ada', '/inbox-items/1/inbox'));

        self::assertSame('1', self::at($document, 'data', 'id'));
        self::assertSame('https://example.test/inbox', self::at($document, 'data', 'links', 'self', 'href'));

        // The access scope keeps ada's inbox from grace.
        $hidden = $this->decode($this->as('grace', '/inbox-items/1/inbox'));
        self::assertArrayHasKey('data', $hidden);
        self::assertNull($hidden['data']);
    }

    #[Test]
    public function anActionRunsOnTheCallersResourceAndCommits(): void
    {
        $response = $this->as('ada', '/inbox/-actions/mark-read', 'POST');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('1', self::at($this->decode($response), 'data', 'id'));
        self::assertSame(0, self::at($this->decode($response), 'data', 'attributes', 'unread'));
        self::assertSame(['1'], MarkInboxRead::$committed);
        self::assertSame(0, self::at($this->decode($this->as('ada', '/inbox')), 'data', 'attributes', 'unread'));
        self::assertSame(1, self::at($this->decode($this->as('grace', '/inbox')), 'data', 'attributes', 'unread'));

        self::assertSame(Response::HTTP_NOT_FOUND, $this->as('hopper', '/inbox/-actions/mark-read', 'POST')->getStatusCode());
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anAtomicOperationAddressesTheSingletonByRef(): void
    {
        $response = $this->atomic('ada', [['op' => 'update', 'ref' => ['type' => 'inboxes', 'id' => '1'], 'data' => $this->rename('1', 'By ref')['data']]]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('By ref', self::at($this->decode($response), 'atomic:results', '0', 'data', 'attributes', 'title'));

        // A ref to another caller's row finds nothing in the caller's scope.
        $refused = $this->atomic('ada', [['op' => 'update', 'ref' => ['type' => 'inboxes', 'id' => '2'], 'data' => $this->rename('2', 'Hijacked')['data']]]);
        self::assertSame(Response::HTTP_NOT_FOUND, $refused->getStatusCode());
        self::assertSame('Grace', self::at($this->decode($this->as('grace', '/inbox')), 'data', 'attributes', 'title'));
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anAtomicOperationAddressesTheSingletonByHref(): void
    {
        $response = $this->atomic('ada', [
            ['op' => 'update', 'href' => '/inbox', 'data' => $this->rename('1', 'By href')['data']],
            ['op' => 'update', 'href' => '/inbox/relationships/pinned', 'data' => ['type' => 'inbox-items', 'id' => '1']],
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('By href', self::at($this->decode($response), 'atomic:results', '0', 'data', 'attributes', 'title'));
        self::assertSame('1', self::at($this->decode($this->as('ada', '/inbox/pinned')), 'data', 'id'));
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anAtomicHrefToTheSingletonIsRefusedLikeTheRoute(): void
    {
        $conflict = $this->atomic('ada', [['op' => 'update', 'href' => '/inbox', 'data' => $this->rename('2', 'Hijacked')['data']]]);
        self::assertSame(Response::HTTP_CONFLICT, $conflict->getStatusCode());
        self::assertSame('/atomic:operations/0/data/id', self::at($this->decode($conflict), 'errors', '0', 'source', 'pointer'));

        $missing = $this->atomic('hopper', [['op' => 'update', 'href' => '/inbox', 'data' => $this->rename('1', 'Nobody')['data']]]);
        self::assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());
        self::assertSame('/atomic:operations/0', self::at($this->decode($missing), 'errors', '0', 'source', 'pointer'));

        $add = $this->atomic('ada', [['op' => 'add', 'href' => '/inbox', 'data' => ['type' => 'inboxes', 'attributes' => ['title' => 'New']]]]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $add->getStatusCode());
        self::assertSame('ATOMIC_HREF_INVALID', self::at($this->decode($add), 'errors', '0', 'code'));

        $remove = $this->atomic('ada', [['op' => 'remove', 'href' => '/inbox']]);
        self::assertGreaterThanOrEqual(400, $remove->getStatusCode());
        self::assertSame('Ada', self::at($this->decode($this->as('ada', '/inbox')), 'data', 'attributes', 'title'));
    }

    #[Test]
    public function scopesTreatTheSingletonAsOneResource(): void
    {
        self::assertSame(Response::HTTP_OK, $this->as('ada', '/inbox', scopes: 'inboxes:read')->getStatusCode());

        $refused = $this->as('ada', '/inbox', scopes: 'inboxes:list');
        self::assertSame(Response::HTTP_FORBIDDEN, $refused->getStatusCode());
        self::assertSame(['inboxes:read'], self::at($this->decode($refused), 'errors', '0', 'meta', 'requiredScopes'));

        $action = $this->as('ada', '/inbox/-actions/mark-read', 'POST', scopes: 'inboxes:read');
        self::assertSame(Response::HTTP_FORBIDDEN, $action->getStatusCode());
        self::assertSame(['inboxes:write'], self::at($this->decode($action), 'errors', '0', 'meta', 'requiredScopes'));
    }

    #[Test]
    public function aSerializerComposesASingleton(): void
    {
        $document = $this->decode($this->as('ada', '/preferences'));

        self::assertSame('p-ada', self::at($document, 'data', 'id'));
        self::assertSame('dark', self::at($document, 'data', 'attributes', 'theme'));
        self::assertSame('https://example.test/preferences', self::at($document, 'data', 'links', 'self', 'href'));

        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $this->as('ada', '/preferences', 'PATCH', ['data' => ['type' => 'preferences', 'id' => 'p-ada']])->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->as('ada', '/preferences/p-ada')->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->handle('/preferences')->getStatusCode());
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theDocumentProjectsTheSingletonsPaths(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $document = $factory->forServer(null)->toArray();

        $paths = \array_keys(self::array($document['paths'] ?? null));
        $inboxPaths = \array_values(\array_filter($paths, static fn(string $path): bool => \str_starts_with($path, '/inbox') && !\str_starts_with($path, '/inbox-items')));
        \sort($inboxPaths);
        self::assertSame(['/inbox', '/inbox/-actions/mark-read', '/inbox/items', '/inbox/pinned', '/inbox/relationships/items', '/inbox/relationships/pinned'], $inboxPaths);
        self::assertSame(['get', 'patch'], \array_keys(self::array(self::at($document, 'paths', '/inbox'))));
        self::assertSame('fetchOne.inboxes', self::at($document, 'paths', '/inbox', 'get', 'operationId'));
        self::assertSame('/inbox', self::at($document, 'components', 'schemas', 'InboxesResource', 'x-singleton'));
        self::assertSame(GeneratorContract::SINGLETONS, self::at($document, 'info', 'x-generator', 'contract'));
        self::assertContains('/preferences', $paths);
        self::assertNotContains('/preferences/{id}', $paths);

        // The firewall's access_control on /inbox refuses an anonymous caller, so the
        // singleton's fetch documents a 401; the unguarded inbox-items read does not.
        self::assertArrayHasKey('401', self::array(self::at($document, 'paths', '/inbox', 'get', 'responses')));
        self::assertArrayNotHasKey('401', self::array(self::at($document, 'paths', '/inbox-items/{id}', 'get', 'responses')));
    }

    /**
     * @param array<string, mixed>|null $body
     */
    protected function as(string $user, string $path, string $method = 'GET', ?array $body = null, ?string $scopes = null): Response
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer ' . $user];
        if ($scopes !== null) {
            $server['HTTP_X_GRANTED_SCOPES'] = $scopes;
        }

        return $this->handle($path, $method, $body, $server);
    }

    /**
     * @param list<array<string, mixed>> $operations
     */
    protected function atomic(string $user, array $operations): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $request = Request::create('/operations', 'POST', server: [
            'CONTENT_TYPE' => self::ATOMIC,
            'HTTP_ACCEPT' => self::ATOMIC,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $user,
        ], content: \json_encode(['atomic:operations' => $operations], \JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $this->restoreHandlers();

        return $response;
    }

    /**
     * @return array{data: array<string, mixed>}
     */
    private function rename(string $id, string $title): array
    {
        return ['data' => ['type' => 'inboxes', 'id' => $id, 'attributes' => ['title' => $title]]];
    }

    /**
     * @return list<string>
     */
    private static function ids(mixed $data): array
    {
        self::assertIsArray($data);
        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            self::assertIsString($resource['id'] ?? null);
            $ids[] = $resource['id'];
        }
        \sort($ids);

        return $ids;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function array(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    private static function at(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
