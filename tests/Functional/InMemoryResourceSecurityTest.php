<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecuredWidgetFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecurityTestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The declarative-authorization suite over the **in-memory** provider/persister
 * (bundle ADR 0043): proves the authorization seam is provider-agnostic — the same
 * `securedWidgets` role gates behave identically without Doctrine, and a denied
 * write leaves the in-memory store unchanged.
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
    public function read_is_granted_for_a_user_and_unauthorized_without_auth(): void
    {
        self::assertSame(200, $this->request('/securedWidgets/1', auth: 'user')->getStatusCode());
        self::assertSame(401, $this->request('/securedWidgets/1')->getStatusCode());
    }

    #[Test]
    public function create_is_forbidden_for_a_user_and_leaves_the_store_unchanged(): void
    {
        $response = $this->request('/securedWidgets', 'POST', [
            'data' => ['type' => 'securedWidgets', 'attributes' => ['name' => 'fresh']],
        ], auth: 'user');

        self::assertSame(403, $response->getStatusCode());

        $document = $this->decode($this->request('/securedWidgets', auth: 'user'));
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertCount(1, $data);
    }

    #[Test]
    public function create_succeeds_for_an_admin(): void
    {
        $response = $this->request('/securedWidgets', 'POST', [
            'data' => ['type' => 'securedWidgets', 'attributes' => ['name' => 'fresh']],
        ], auth: 'admin');

        self::assertSame(201, $response->getStatusCode());
    }

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
}
