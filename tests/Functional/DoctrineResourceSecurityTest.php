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
 *
 * Authentication runs through the shipped {@see \haddowg\JsonApiBundle\Testing\JsonApiBrowser::actingAs()}
 * — a stateless Bearer access token the firewall resolves to the seeded user — so the
 * suite dogfoods the same auth path a consumer would use.
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
        $this->browser()->actingAs('user')->get('/securedWidgets/1')->assertFetchedOne();
    }

    #[Test]
    public function read_is_unauthorized_without_authentication(): void
    {
        $this->browser()
            ->get('/securedWidgets/1')
            ->getErrors()
            ->assertStatus(401)
            ->assertContentType()
            ->assertHasError(status: '401');
    }

    // --- per-operation overrides gate independently of the default ------------

    #[Test]
    public function create_requires_admin_and_a_role_user_is_forbidden(): void
    {
        $this->browser()
            ->actingAs('user')
            ->post('/securedWidgets', [
                'data' => ['type' => 'securedWidgets', 'attributes' => ['name' => 'fresh']],
            ])
            ->getErrors()
            ->assertStatus(403)
            ->assertContentType()
            ->assertHasError(status: '403');

        // The abort happened before persistence: only the seeded row remains.
        $this->browser()->actingAs('admin')->get('/securedWidgets')->assertFetchedMany()->assertCollectionCount(1);
    }

    #[Test]
    public function create_succeeds_for_an_admin(): void
    {
        $this->browser()
            ->actingAs('admin')
            ->post('/securedWidgets', [
                'data' => ['type' => 'securedWidgets', 'attributes' => ['name' => 'fresh']],
            ])
            ->assertCreated();

        $this->browser()->actingAs('admin')->get('/securedWidgets')->assertFetchedMany()->assertCollectionCount(2);
    }

    #[Test]
    public function update_uses_the_role_user_default_not_the_admin_create_override(): void
    {
        // securityUpdate is unset, so update falls back to the ROLE_USER default — a
        // plain user (forbidden to create) may still update.
        $this->browser()
            ->actingAs('user')
            ->patch('/securedWidgets/1', [
                'data' => ['type' => 'securedWidgets', 'id' => '1', 'attributes' => ['name' => 'renamed']],
            ])
            ->assertFetchedOne();
    }

    #[Test]
    public function delete_requires_admin_and_a_role_user_is_forbidden(): void
    {
        $this->browser()
            ->actingAs('user')
            ->delete('/securedWidgets/1')
            ->getErrors()
            ->assertStatus(403)
            ->assertHasError(status: '403');

        // The row survives: the delete never ran (abort before side-effect).
        $this->browser()->actingAs('user')->get('/securedWidgets/1')->assertFetchedOne();
    }

    #[Test]
    public function delete_succeeds_for_an_admin(): void
    {
        $this->browser()->actingAs('admin')->delete('/securedWidgets/1')->assertNoContent();

        $this->browser()->actingAs('admin')->get('/securedWidgets/1')->getDocument()->assertStatus(404);
    }

    // --- ownership Voter gate: is_granted('EDIT', object) ---------------------

    #[Test]
    public function owner_may_update_their_widget(): void
    {
        $this->browser()
            ->actingAs('ada')
            ->patch('/ownedWidgets/1', [
                'data' => ['type' => 'ownedWidgets', 'id' => '1', 'attributes' => ['name' => 'edited']],
            ])
            ->assertFetchedOne()
            ->assertHasAttribute('name', 'edited');
    }

    #[Test]
    public function a_non_owner_is_forbidden_to_update(): void
    {
        $this->browser()
            ->actingAs('grace')
            ->patch('/ownedWidgets/1', [
                'data' => ['type' => 'ownedWidgets', 'id' => '1', 'attributes' => ['name' => 'edited']],
            ])
            ->getErrors()
            ->assertStatus(403)
            ->assertHasError(status: '403');

        // No write happened: a fresh read by the owner is whole-member EXACT (a leaked
        // or mutated attribute would fail) — the seeded widget, name intact, parent
        // still empty.
        $this->browser()
            ->actingAs('ada')
            ->get('/ownedWidgets/1')
            ->assertFetchedOneExact([
                'type' => 'ownedWidgets',
                'id' => '1',
                'attributes' => ['name' => 'ada-widget', 'owner' => 'ada'],
                'relationships' => [
                    'parent' => [
                        'data' => null,
                        'links' => [
                            'related' => 'http://localhost/ownedWidgets/1/parent',
                            'self' => 'http://localhost/ownedWidgets/1/relationships/parent',
                        ],
                    ],
                ],
                'links' => ['self' => 'http://localhost/ownedWidgets/1'],
            ]);
    }

    #[Test]
    public function a_non_owner_is_forbidden_to_read_the_single_resource(): void
    {
        $this->browser()
            ->actingAs('grace')
            ->get('/ownedWidgets/1')
            ->getErrors()
            ->assertStatus(403)
            ->assertHasError(status: '403');
    }

    // --- collection gate: securityList overrides the per-object default ---------

    #[Test]
    public function any_authenticated_user_may_list_owned_widgets(): void
    {
        // The read/list split: grace cannot read a single widget she does not own (the
        // per-object EDIT default, asserted above), but `securityList:
        // is_granted('ROLE_USER')` lets her LIST the collection. The gate is
        // all-or-nothing (not row-level), so she sees every row.
        $this->browser()
            ->actingAs('grace')
            ->get('/ownedWidgets')
            ->assertFetchedMany();
    }

    #[Test]
    public function an_anonymous_collection_read_is_forbidden_by_security_list(): void
    {
        // securityList gates the collection BEFORE the query: an unauthenticated caller
        // (no ROLE_USER) is denied outright — a 401, not a row-filtered empty list.
        $this->browser()
            ->get('/ownedWidgets')
            ->getErrors()
            ->assertStatus(401)
            ->assertHasError(status: '401');
    }

    // --- relationship mutation: gated by the update expression (the parent) ----

    #[Test]
    public function owner_may_mutate_a_relationship(): void
    {
        // securityUpdate is unset, so relationship mutation falls back to the
        // ownership default `is_granted('EDIT', object)` evaluated against the
        // parent (`ownedWidgets/1`). ada owns it, so the PATCH is granted.
        $this->browser()
            ->actingAs('ada')
            ->patch('/ownedWidgets/1/relationships/parent', [
                'data' => ['type' => 'ownedWidgets', 'id' => '2'],
            ])
            ->getDocument()
            ->assertStatus(200);

        // The mutation persisted: a fresh linkage read reflects the new parent.
        $this->browser()
            ->actingAs('ada')
            ->get('/ownedWidgets/1/relationships/parent')
            ->getDocument()
            ->assertStatus(200)
            ->assertHasType('ownedWidgets')
            ->assertHasId('2');
    }

    #[Test]
    public function a_non_owner_is_forbidden_to_mutate_a_relationship(): void
    {
        // grace does not own ownedWidgets/1, so the relationship gate denies the
        // mutation (subject = the parent) before the persister applies it.
        $this->browser()
            ->actingAs('grace')
            ->patch('/ownedWidgets/1/relationships/parent', [
                'data' => ['type' => 'ownedWidgets', 'id' => '2'],
            ])
            ->getErrors()
            ->assertStatus(403)
            ->assertContentType()
            ->assertHasError(status: '403');

        // The abort happened before the persister: the parent linkage is unchanged
        // (still empty) on a fresh read by the owner.
        $this->browser()
            ->actingAs('ada')
            ->get('/ownedWidgets/1/relationships/parent')
            ->getDocument()
            ->assertStatus(200)
            ->assertNoData();
    }

    // --- no security declared → ungated ---------------------------------------

    #[Test]
    public function an_unsecured_resource_is_ungated_even_unauthenticated(): void
    {
        $this->browser()->get('/openWidgets/1')->assertFetchedOne();

        $this->browser()
            ->post('/openWidgets', [
                'data' => ['type' => 'openWidgets', 'attributes' => ['name' => 'anon']],
            ])
            ->assertCreated();
    }
}
