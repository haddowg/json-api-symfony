<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\OpenWidgetEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\OwnedWidgetEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\SecuredWidgetEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\SecurityTestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The declarative-authorization suite over the Doctrine path (bundle ADR 0043): a
 * resource declares `#[AsJsonApiResource(security: …)]` expressions, the built-in
 * {@see \haddowg\JsonApiBundle\Security\ResourceSecuritySubscriber} evaluates them at
 * the lifecycle hooks behind a real firewall, and a denial renders a JSON:API `403`
 * (or `401` unauthenticated) with **no commit/side-effect**.
 *
 * It asserts: a role gate denies a forbidden user / admits an allowed one; the
 * per-operation overrides (create/delete `ROLE_ADMIN`) gate independently of the
 * `ROLE_USER` default; an ownership Voter gate (`is_granted('EDIT', object)`); an
 * unauthenticated request is `401`; a single read is gated; a resource with no
 * security is ungated; the abort happens before persistence (the store is unchanged);
 * and the error renders as a proper JSON:API document.
 */
final class DoctrineResourceSecurityTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return SecurityTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $entityManager->persist(new SecuredWidgetEntity(1, 'seeded'));
        $entityManager->persist(new OwnedWidgetEntity(1, 'ada-widget', 'ada'));
        // A second owned widget serves as the `parent` linkage target for the
        // relationship-mutation gate (both owned by ada).
        $entityManager->persist(new OwnedWidgetEntity(2, 'ada-parent', 'ada'));
        $entityManager->persist(new OpenWidgetEntity(1, 'open'));
        $entityManager->flush();
        $entityManager->clear();
    }

    // --- role gate: read + update default (ROLE_USER) -------------------------

    #[Test]
    public function read_is_granted_for_an_authenticated_user(): void
    {
        $response = $this->request('/securedWidgets/1', auth: 'user');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/vnd.api+json', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function read_is_unauthorized_without_authentication(): void
    {
        $response = $this->request('/securedWidgets/1');

        self::assertSame(401, $response->getStatusCode());
        $this->assertJsonApiError($response, '401', 'Unauthorized');
    }

    // --- per-operation overrides gate independently of the default ------------

    #[Test]
    public function create_requires_admin_and_a_role_user_is_forbidden(): void
    {
        $response = $this->request('/securedWidgets', 'POST', [
            'data' => ['type' => 'securedWidgets', 'attributes' => ['name' => 'fresh']],
        ], auth: 'user');

        self::assertSame(403, $response->getStatusCode());
        $this->assertJsonApiError($response, '403', 'Forbidden');

        // The abort happened before persistence: only the seeded row remains.
        self::assertCount(1, $this->collection('/securedWidgets', 'admin'));
    }

    #[Test]
    public function create_succeeds_for_an_admin(): void
    {
        $response = $this->request('/securedWidgets', 'POST', [
            'data' => ['type' => 'securedWidgets', 'attributes' => ['name' => 'fresh']],
        ], auth: 'admin');

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(2, $this->collection('/securedWidgets', 'admin'));
    }

    #[Test]
    public function update_uses_the_role_user_default_not_the_admin_create_override(): void
    {
        // securityUpdate is unset, so update falls back to the ROLE_USER default — a
        // plain user (forbidden to create) may still update.
        $response = $this->request('/securedWidgets/1', 'PATCH', [
            'data' => ['type' => 'securedWidgets', 'id' => '1', 'attributes' => ['name' => 'renamed']],
        ], auth: 'user');

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function delete_requires_admin_and_a_role_user_is_forbidden(): void
    {
        $response = $this->request('/securedWidgets/1', 'DELETE', auth: 'user');

        self::assertSame(403, $response->getStatusCode());

        // The row survives: the delete never ran (abort before side-effect).
        self::assertSame(200, $this->request('/securedWidgets/1', auth: 'user')->getStatusCode());
    }

    #[Test]
    public function delete_succeeds_for_an_admin(): void
    {
        $response = $this->request('/securedWidgets/1', 'DELETE', auth: 'admin');

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(404, $this->request('/securedWidgets/1', auth: 'admin')->getStatusCode());
    }

    // --- ownership Voter gate: is_granted('EDIT', object) ---------------------

    #[Test]
    public function owner_may_update_their_widget(): void
    {
        $response = $this->request('/ownedWidgets/1', 'PATCH', [
            'data' => ['type' => 'ownedWidgets', 'id' => '1', 'attributes' => ['name' => 'edited']],
        ], auth: 'ada');

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function a_non_owner_is_forbidden_to_update(): void
    {
        $response = $this->request('/ownedWidgets/1', 'PATCH', [
            'data' => ['type' => 'ownedWidgets', 'id' => '1', 'attributes' => ['name' => 'edited']],
        ], auth: 'grace');

        self::assertSame(403, $response->getStatusCode());

        // No write happened: the name is unchanged on a fresh read by the owner.
        $document = $this->decode($this->request('/ownedWidgets/1', auth: 'ada'));
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('ada-widget', $attributes['name'] ?? null);
    }

    #[Test]
    public function a_non_owner_is_forbidden_to_read_the_single_resource(): void
    {
        $response = $this->request('/ownedWidgets/1', auth: 'grace');

        self::assertSame(403, $response->getStatusCode());
    }

    // --- relationship mutation: gated by the update expression (the parent) ----

    #[Test]
    public function owner_may_mutate_a_relationship(): void
    {
        // securityUpdate is unset, so relationship mutation falls back to the
        // ownership default `is_granted('EDIT', object)` evaluated against the
        // parent (`ownedWidgets/1`). ada owns it, so the PATCH is granted.
        $response = $this->request('/ownedWidgets/1/relationships/parent', 'PATCH', [
            'data' => ['type' => 'ownedWidgets', 'id' => '2'],
        ], auth: 'ada');

        self::assertSame(200, $response->getStatusCode());

        // The mutation persisted: a fresh linkage read reflects the new parent.
        $document = $this->decode($this->request('/ownedWidgets/1/relationships/parent', auth: 'ada'));
        self::assertSame(['type' => 'ownedWidgets', 'id' => '2'], $document['data'] ?? null);
    }

    #[Test]
    public function a_non_owner_is_forbidden_to_mutate_a_relationship(): void
    {
        // grace does not own ownedWidgets/1, so the relationship gate denies the
        // mutation (subject = the parent) before the persister applies it.
        $response = $this->request('/ownedWidgets/1/relationships/parent', 'PATCH', [
            'data' => ['type' => 'ownedWidgets', 'id' => '2'],
        ], auth: 'grace');

        self::assertSame(403, $response->getStatusCode());
        $this->assertJsonApiError($response, '403', 'Forbidden');

        // The abort happened before the persister: the parent linkage is unchanged
        // (still empty) on a fresh read by the owner.
        $document = $this->decode($this->request('/ownedWidgets/1/relationships/parent', auth: 'ada'));
        self::assertNull($document['data'] ?? null);
    }

    // --- no security declared → ungated ---------------------------------------

    #[Test]
    public function an_unsecured_resource_is_ungated_even_unauthenticated(): void
    {
        self::assertSame(200, $this->request('/openWidgets/1')->getStatusCode());

        $created = $this->request('/openWidgets', 'POST', [
            'data' => ['type' => 'openWidgets', 'attributes' => ['name' => 'anon']],
        ]);
        self::assertSame(201, $created->getStatusCode());
    }

    // --- helpers --------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $path, string $method = 'GET', ?array $body = null, ?string $auth = null): Response
    {
        $extraServer = $auth !== null
            ? ['PHP_AUTH_USER' => $auth, 'PHP_AUTH_PW' => 'pass']
            : [];

        return $this->handle($path, $method, $body, $extraServer);
    }

    /**
     * The primary `data` array of a collection read as the given user.
     *
     * @return list<mixed>
     */
    private function collection(string $path, string $auth): array
    {
        $document = $this->decode($this->request($path, auth: $auth));
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        /** @var list<mixed> $data */
        return $data;
    }

    private function assertJsonApiError(Response $response, string $status, string $title): void
    {
        self::assertStringStartsWith('application/vnd.api+json', (string) $response->headers->get('Content-Type'));
        $document = $this->decode($response);
        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);
        $first = $errors[0] ?? null;
        self::assertIsArray($first);
        self::assertSame($status, $first['status'] ?? null);
        self::assertSame($title, $first['title'] ?? null);
    }
}
