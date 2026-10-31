<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\ResourcesOnServerPass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The same type name served by two resource classes on two servers, each with its own
 * security. The named server's `securedWidgets` declares the full set:
 * a `ROLE_USER` default, a read gate only an admin passes for "classified" and a list
 * gate `reader` fails. The default server's declares only a read gate that refuses
 * "classified" to everyone, admin included, and nothing for any other operation.
 *
 * Seeded by each test class: widgets 1 "one", 2 "two", 3 "classified" and 4 "four".
 * Widget 1's partner is 3 and its partners are 2 and 3.
 *
 * @phpstan-require-extends JsonApiFunctionalTestCase
 */
trait ServerKeyedSecurityConformance
{
    #[Test]
    public function each_server_enforces_only_its_own_read_gate(): void
    {
        self::assertSame(200, $this->request(null, '/securedWidgets/1')->getStatusCode());
        self::assertSame(401, $this->request(null, $this->named('/securedWidgets/1'))->getStatusCode());

        self::assertSame(403, $this->request('admin', '/securedWidgets/3')->getStatusCode());
        self::assertSame(200, $this->request('admin', $this->named('/securedWidgets/3'))->getStatusCode());
    }

    #[Test]
    public function a_server_that_declares_no_list_gate_does_not_inherit_one(): void
    {
        self::assertSame(200, $this->request(null, '/securedWidgets')->getStatusCode());
        self::assertSame(200, $this->request('reader', '/securedWidgets')->getStatusCode());

        self::assertSame(401, $this->request(null, $this->named('/securedWidgets'))->getStatusCode());
        self::assertSame(403, $this->request('reader', $this->named('/securedWidgets'))->getStatusCode());
    }

    #[Test]
    public function a_server_that_declares_no_update_gate_does_not_inherit_one(): void
    {
        $body = ['data' => ['type' => 'securedWidgets', 'id' => '2', 'attributes' => ['name' => 'renamed']]];

        self::assertSame(401, $this->request(null, $this->named('/securedWidgets/2'), 'PATCH', $body)->getStatusCode());

        $response = $this->request(null, '/securedWidgets/2', 'PATCH', $body);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    public function an_included_to_one_follows_the_read_gate_of_the_requests_server(): void
    {
        $default = $this->document('admin', '/securedWidgets/1?include=partner');
        self::assertNull($this->relationship($default, 'partner')['data'] ?? null);
        self::assertSame([], $this->included($default));

        $named = $this->document('admin', $this->named('/securedWidgets/1?include=partner'));
        self::assertSame(['type' => 'securedWidgets', 'id' => '3'], $this->relationship($named, 'partner')['data'] ?? null);
        self::assertSame(['securedWidgets:3'], $this->included($named));
    }

    #[Test]
    public function an_included_to_many_follows_the_list_gate_of_the_requests_server(): void
    {
        $default = $this->document('reader', '/securedWidgets/1?include=partners');
        self::assertSame(
            [['type' => 'securedWidgets', 'id' => '2'], ['type' => 'securedWidgets', 'id' => '3']],
            $this->relationship($default, 'partners')['data'] ?? null,
        );
        self::assertSame(['securedWidgets:2', 'securedWidgets:3'], $this->included($default));

        $named = $this->document('reader', $this->named('/securedWidgets/1?include=partners'));
        self::assertArrayNotHasKey('data', $this->relationship($named, 'partners'));
        self::assertSame([], $this->included($named));
    }

    #[Test]
    public function each_servers_openapi_document_shows_only_its_own_security(): void
    {
        // A secured operation carries its own requirement; the others carry none.
        $default = $this->operations(null);
        self::assertArrayNotHasKey('security', $default['list']);
        self::assertArrayHasKey('security', $default['read']);
        self::assertArrayNotHasKey('security', $default['update']);

        $named = $this->operations(ResourcesOnServerPass::SERVER);
        self::assertArrayHasKey('security', $named['list']);
        self::assertArrayHasKey('security', $named['read']);
        self::assertArrayHasKey('security', $named['update']);
    }

    private function named(string $path): string
    {
        return '/' . ResourcesOnServerPass::SERVER . $path;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(?string $user, string $path, string $method = 'GET', ?array $body = null): Response
    {
        return $this->handle($path, $method, $body, $user !== null ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $user] : []);
    }

    /**
     * @return array<string, mixed>
     */
    private function document(string $user, string $path): array
    {
        $response = $this->request($user, $path);
        self::assertSame(200, $response->getStatusCode(), $path . ': ' . $response->getContent());

        return $this->decode($response);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function relationship(array $document, string $name): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);
        $relationship = $relationships[$name] ?? null;
        self::assertIsArray($relationship, $name . ' is rendered');

        /** @var array<string, mixed> $relationship */
        return $relationship;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function included(array $document): array
    {
        $identities = [];
        foreach ((array) ($document['included'] ?? []) as $resource) {
            self::assertIsArray($resource);
            self::assertIsString($resource['type'] ?? null);
            self::assertIsString($resource['id'] ?? null);
            $identities[] = $resource['type'] . ':' . $resource['id'];
        }
        \sort($identities);

        return $identities;
    }

    /**
     * The `securedWidgets` collection read, single read and update operations in a
     * server's OpenAPI document.
     *
     * @return array{list: array<string, mixed>, read: array<string, mixed>, update: array<string, mixed>}
     */
    private function operations(?string $server): array
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $paths = $factory->forServer($server)->toArray()['paths'] ?? null;
        self::assertIsArray($paths);

        $collection = null;
        $single = null;
        foreach ($paths as $path => $item) {
            if (\str_ends_with((string) $path, '/securedWidgets')) {
                $collection = $item;
            } elseif (\str_ends_with((string) $path, '/securedWidgets/{id}')) {
                $single = $item;
            }
        }
        self::assertIsArray($collection);
        self::assertIsArray($single);
        self::assertIsArray($collection['get'] ?? null);
        self::assertIsArray($single['get'] ?? null);
        self::assertIsArray($single['patch'] ?? null);

        /** @var array{list: array<string, mixed>, read: array<string, mixed>, update: array<string, mixed>} */
        return ['list' => $collection['get'], 'read' => $single['get'], 'update' => $single['patch']];
    }
}
