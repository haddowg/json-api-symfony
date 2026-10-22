<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Testing\JsonApiBrowser;
use haddowg\JsonApiBundle\Testing\WrapsKernelBrowser;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecuredWidgetFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see WrapsKernelBrowser} on a suite whose base `createClient()` returns its own
 * {@see \Symfony\Bundle\FrameworkBundle\KernelBrowser}: the browser it hands back carries
 * the base client's settings and the full assertion set, and the handler stack stays
 * balanced (PHPUnit flags the test risky otherwise).
 */
final class WrapsKernelBrowserTest extends KernelBrowserWebTestCase
{
    use WrapsKernelBrowser;

    protected function setUp(): void
    {
        InMemorySecuredWidgetFactory::reset();
    }

    #[Test]
    public function it_wraps_the_base_client_once_per_client(): void
    {
        $client = static::createClient();
        self::assertNotInstanceOf(JsonApiBrowser::class, $client);

        $browser = $this->jsonApi($client);

        self::assertSame($browser, $this->jsonApi($client));
        self::assertSame($browser, $this->jsonApi($browser));
        self::assertSame($client->getKernel(), $browser->getKernel());
        self::assertSame('app', $browser->getServerParameter('HTTP_X_SUITE'));
    }

    #[Test]
    #[Group('spec:fetching-resources')]
    public function the_wrapped_browser_authenticates_and_asserts(): void
    {
        $client = static::createClient();

        $this->jsonApi($client)
            ->get('/securedWidgets/1')
            ->getErrors()
            ->assertStatus(401)
            ->assertHasError(status: '401');

        $this->jsonApi($client)
            ->actingAs('user')
            ->get('/securedWidgets/1')
            ->assertFetchedOne()
            ->assertHasType('securedWidgets')
            ->assertHasId('1');

        // Asking again for the same client reaches the last response.
        $this->jsonApi($client)->assertStatus(200);
    }

    #[Test]
    #[Group('spec:crud')]
    public function a_write_then_read_shares_one_kernel(): void
    {
        $browser = $this->jsonApi(static::createClient())->actingAs('admin');

        $browser->post('/securedWidgets', ['data' => ['type' => 'securedWidgets', 'attributes' => ['name' => 'fresh']]])
            ->assertCreated();
        $document = \json_decode((string) $browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertIsArray($document['data']);
        $id = $document['data']['id'] ?? null;
        self::assertIsString($id);

        $browser->get('/securedWidgets/' . $id)
            ->assertFetchedOne()
            ->assertHasId($id);
    }

    #[Test]
    public function handlers_left_behind_during_the_test_are_popped_afterwards(): void
    {
        static::createClient();

        // What a kernel boot does on some Symfony versions: handlers pushed and never
        // popped. The trait's #[After] hook restores the pre-test stack.
        \set_error_handler(static fn(): bool => false);
        \set_exception_handler(static fn(\Throwable $e): null => null);

        $this->addToAssertionCount(1);
    }
}
