<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecuredWidgetFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecurityTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * The declarative-authorization suite over the **in-memory** provider/persister
 * (bundle ADR 0043): proves the authorization seam is provider-agnostic — the same
 * `securedWidgets` role gates behave identically without Doctrine, and a denied
 * write leaves the in-memory store unchanged.
 *
 * Authentication runs through the shipped {@see \haddowg\JsonApiBundle\Testing\JsonApiBrowser::actingAs()}
 * — a stateless Bearer access token resolved to a seeded user by the test app's
 * firewall — so the suite dogfoods the same auth path a consumer would use.
 */
final class InMemoryResourceSecurityTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return InMemorySecurityTestKernel::class;
    }

    protected function afterBoot(): void
    {
        InMemorySecuredWidgetFactory::reset();
    }

    #[Test]
    public function read_is_unauthorized_without_authentication(): void
    {
        $this->browser()->get('/securedWidgets/1')->getErrors()->assertStatus(401)->assertHasError(status: '401');
    }

    #[Test]
    public function read_is_granted_for_a_user(): void
    {
        $this->browser()->actingAs('user')->get('/securedWidgets/1')->assertFetchedOne();
    }

    #[Test]
    public function the_read_gate_covers_the_related_endpoint(): void
    {
        // A read-gated resource is not reachable via its related endpoint: the parent
        // read-security gate fires on /{id}/{rel} too.
        $this->browser()->get('/securedWidgets/1/partner')->getErrors()->assertStatus(401)->assertHasError(status: '401');
        $this->browser()->actingAs('user')->get('/securedWidgets/1/partner')->assertStatus(200);
    }

    #[Test]
    public function the_read_gate_covers_the_relationship_endpoint(): void
    {
        // The relationship-linkage endpoint /{id}/relationships/{rel} is likewise gated.
        $this->browser()->get('/securedWidgets/1/relationships/partner')->getErrors()->assertStatus(401)->assertHasError(status: '401');
        $this->browser()->actingAs('user')->get('/securedWidgets/1/relationships/partner')->assertStatus(200);
    }

    #[Test]
    public function a_public_relation_read_overrides_the_parent_gate_even_unauthenticated(): void
    {
        // `publicPartner` declares security(read: false) → its read endpoints are PUBLIC,
        // reachable without authentication even though the parent `securedWidgets` requires
        // ROLE_USER. The relation is MORE permissive than the resource it hangs off.
        $this->browser()->get('/securedWidgets/1/publicPartner')->assertStatus(200);
        $this->browser()->get('/securedWidgets/1/relationships/publicPartner')->assertStatus(200);
    }

    #[Test]
    public function an_admin_relation_read_overrides_the_parent_gate_to_be_more_restrictive(): void
    {
        // `adminPartner` declares security(read: is_granted('ROLE_ADMIN')) → a plain
        // ROLE_USER may read the resource (and its inherited `partner`) but NOT this
        // relation; only an admin may. The relation is MORE restrictive than its parent.
        $this->browser()->actingAs('user')->get('/securedWidgets/1/adminPartner')->getErrors()->assertStatus(403)->assertHasError(status: '403');
        $this->browser()->actingAs('user')->get('/securedWidgets/1/relationships/adminPartner')->getErrors()->assertStatus(403)->assertHasError(status: '403');

        $this->browser()->actingAs('admin')->get('/securedWidgets/1/adminPartner')->assertStatus(200);
        $this->browser()->actingAs('admin')->get('/securedWidgets/1/relationships/adminPartner')->assertStatus(200);
    }

    #[Test]
    public function create_is_forbidden_for_a_user_and_leaves_the_store_unchanged(): void
    {
        $this->browser()
            ->actingAs('user')
            ->post('/securedWidgets', [
                'data' => ['type' => 'securedWidgets', 'attributes' => ['name' => 'fresh']],
            ])
            ->getErrors()
            ->assertStatus(403)
            ->assertHasError(status: '403');

        // The abort happened before persistence: only the seeded row remains.
        $this->browser()->actingAs('user')->get('/securedWidgets')->assertFetchedMany()->assertCollectionCount(1);
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
    }
}
