<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Testing\InteractsWithJsonApi;
use haddowg\JsonApiBundle\Testing\JsonApiBrowser;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecuredWidgetFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecurityTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Proves the two halves of the testing-utility revision end-to-end against a real
 * firewall: the {@see InteractsWithJsonApi} trait gives a stock {@see WebTestCase} a
 * {@see JsonApiBrowser} from `static::createClient()`, and
 * {@see JsonApiBrowser::actingAs()} authenticates **statelessly over a Bearer
 * access token** through the kernel's `access_token` firewall.
 *
 * The witnesses: a request without `actingAs()` is `401`; with `actingAs('user')`
 * the same read is `200` (the Bearer token resolves to the seeded `ROLE_USER`); and
 * a create over the `ROLE_ADMIN` override is `403` as a plain user but `201` as
 * `admin` — the Bearer token carries the role.
 */
final class JsonApiBrowserWebTestCaseTest extends WebTestCase
{
    use InteractsWithJsonApi;

    protected static function getKernelClass(): string
    {
        return InMemorySecurityTestKernel::class;
    }

    protected function setUp(): void
    {
        InMemorySecuredWidgetFactory::reset();
    }

    #[Test]
    public function create_client_returns_a_json_api_browser(): void
    {
        self::assertInstanceOf(JsonApiBrowser::class, static::createClient());
    }

    #[Test]
    #[Group('spec:fetching-resources')]
    public function an_unauthenticated_read_is_unauthorized(): void
    {
        static::createClient()
            ->get('/securedWidgets/1')
            ->getErrors()
            ->assertStatus(401)
            ->assertHasError(status: '401');
    }

    #[Test]
    #[Group('spec:fetching-resources')]
    public function acting_as_a_user_authenticates_over_a_bearer_token(): void
    {
        static::createClient()
            ->actingAs('user')
            ->get('/securedWidgets/1')
            ->assertFetchedOne()
            ->assertHasType('securedWidgets')
            ->assertHasId('1');
    }

    #[Test]
    #[Group('spec:crud')]
    public function a_plain_user_is_forbidden_to_create_but_an_admin_succeeds(): void
    {
        $document = [
            'data' => ['type' => 'securedWidgets', 'attributes' => ['name' => 'fresh']],
        ];

        // One client across both requests (reboot disabled): re-authenticating just
        // overwrites the Bearer token, so the admin retry hits the same store.
        $client = static::createClient();

        $client->actingAs('user')
            ->post('/securedWidgets', $document)
            ->getErrors()
            ->assertStatus(403)
            ->assertHasError(status: '403');

        $client->actingAs('admin')
            ->post('/securedWidgets', $document)
            ->assertCreated();
    }
}
