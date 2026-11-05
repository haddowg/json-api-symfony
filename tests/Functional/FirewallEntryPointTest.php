<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\EntryPoint\BearerEntryPoint;
use haddowg\JsonApiBundle\Tests\Functional\App\EntryPoint\EntryPointTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\EntryPoint\UnauthorizedHttpExceptionListener;
use haddowg\JsonApiBundle\Tests\Functional\App\Http\HeaderWidgetFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentication failures on a JSON:API route go through the firewall's entry point,
 * so its challenge headers reach the client, and still answer with a JSON:API error
 * document.
 */
#[Group('spec:errors')]
final class FirewallEntryPointTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return EntryPointTestKernel::class;
    }

    protected function afterBoot(): void
    {
        HeaderWidgetFactory::reset();
    }

    #[Test]
    public function anAnonymousRequestGetsTheEntryPointsChallengeOnAJsonApiDocument(): void
    {
        $response = $this->handle('/plainWidgets/1');

        $this->assertAuthenticationRequired($response);
        self::assertSame(BearerEntryPoint::CHALLENGE, $response->headers->get('WWW-Authenticate'));
        self::assertSame('bearer', $response->headers->get('X-Entry-Point'));
    }

    #[Test]
    public function anAnonymousRequestGetsAJsonApi401WhenTheFirewallHasNoEntryPoint(): void
    {
        self::ensureKernelShutdown();
        static::bootKernel(['debug' => false, 'environment' => EntryPointTestKernel::NO_ENTRY_POINT]);

        $response = $this->handle('/plainWidgets/1');

        $this->assertAuthenticationRequired($response);
        self::assertFalse($response->headers->has('X-Entry-Point'));
    }

    #[Test]
    public function anUnauthorizedHttpExceptionKeepsItsChallenge(): void
    {
        $response = $this->handle('/plainWidgets/1', extraServer: [
            'HTTP_AUTHORIZATION' => 'Bearer user',
            UnauthorizedHttpExceptionListener::TRIGGER => '1',
        ]);

        $this->assertAuthenticationRequired($response);
        self::assertSame(UnauthorizedHttpExceptionListener::CHALLENGE, $response->headers->get('WWW-Authenticate'));
    }

    #[Test]
    public function anAuthenticatedButDeniedRequestIsStill403(): void
    {
        $response = $this->handle('/plainWidgets/1', extraServer: ['HTTP_AUTHORIZATION' => 'Bearer guest']);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
        self::assertSame('ACCESS_DENIED', $this->firstError($response)['code'] ?? null);
        self::assertFalse($response->headers->has('WWW-Authenticate'));
    }

    #[Test]
    public function anAuthenticatedRequestIsServed(): void
    {
        $response = $this->handle('/plainWidgets/1', extraServer: ['HTTP_AUTHORIZATION' => 'Bearer user']);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function aNonJsonApiRouteKeepsTheEntryPointsResponse(): void
    {
        $response = $this->handle('/plain');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Sign in with a bearer token', $response->getContent());
        self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        self::assertSame(BearerEntryPoint::CHALLENGE, $response->headers->get('WWW-Authenticate'));
    }

    private function assertAuthenticationRequired(Response $response): void
    {
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
        $error = $this->firstError($response);
        self::assertSame('401', $error['status'] ?? null);
        self::assertSame('AUTHENTICATION_REQUIRED', $error['code'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function firstError(Response $response): array
    {
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertIsArray($errors[0] ?? null);

        /** @var array<string, mixed> $error */
        $error = $errors[0];

        return $error;
    }
}
