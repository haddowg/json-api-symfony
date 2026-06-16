<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\EventListener;

use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Schema\Error\InternalServerError;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\EventListener\ExceptionListener;
use haddowg\JsonApiBundle\Server\ResourceLocator;
use haddowg\JsonApiBundle\Server\ServerFactory;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Characterizes the route-scoped `kernel.exception` listener: an unexpected
 * `\Throwable` on a marked route renders a generic `500` JSON:API document, and
 * the rendered error object is byte-identical to core's
 * {@see InternalServerError::for()} seam (the listener delegates the 500 mapping
 * to core rather than re-implementing it).
 */
final class ExceptionListenerTest extends TestCase
{
    #[Test]
    #[Group('spec:errors')]
    public function unexpectedThrowableIsRedactedWhenDebugIsOff(): void
    {
        $throwable = new \RuntimeException('leaky secret detail', 42);

        $error = $this->handle($throwable, debug: false);

        self::assertSame('500', $error['status'] ?? null);
        self::assertSame('Internal Server Error', $error['title'] ?? null);
        self::assertArrayNotHasKey('code', $error);
        self::assertArrayNotHasKey('detail', $error);
        self::assertArrayNotHasKey('meta', $error);

        self::assertSame($this->seamErrorArray($throwable, false), $error);
    }

    #[Test]
    #[Group('spec:errors')]
    public function unexpectedThrowableIsVerboseWhenDebugIsOn(): void
    {
        $throwable = new \RuntimeException('leaky secret detail', 42);

        $error = $this->handle($throwable, debug: true);

        self::assertSame('500', $error['status'] ?? null);
        self::assertSame('42', $error['code'] ?? null);
        self::assertSame('Internal Server Error', $error['title'] ?? null);
        self::assertSame('leaky secret detail', $error['detail'] ?? null);

        $meta = $error['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertSame(\RuntimeException::class, $meta['exception'] ?? null);
        self::assertArrayHasKey('file', $meta);
        self::assertArrayHasKey('line', $meta);
        self::assertIsArray($meta['trace'] ?? null);

        self::assertSame($this->seamErrorArray($throwable, true), $error);
    }

    /**
     * Fires the listener for `$throwable` on a marked route and returns the first
     * rendered error object.
     *
     * @return array<string, mixed>
     */
    private function handle(\Throwable $throwable, bool $debug): array
    {
        $listener = new ExceptionListener(
            $this->serverProvider(),
            $this->psrHttpFactory(),
            new HttpFoundationFactory(),
            debug: $debug,
        );

        $request = Request::create('/articles', 'GET');
        $request->attributes->set(ExceptionListener::ROUTE_MARKER, true);

        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );

        $listener->onKernelException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(500, $response->getStatusCode());

        return $this->firstError($response);
    }

    /**
     * The error object core's seam produces for the same throwable+debug flag,
     * via the exact `ErrorResponse` rendering path the listener uses, decoded to
     * the document's first error object.
     *
     * @return array<string, mixed>
     */
    private function seamErrorArray(\Throwable $throwable, bool $debug): array
    {
        $psrResponse = ErrorResponse::fromErrors(InternalServerError::for($throwable, $debug))
            ->toPsrResponse($this->server(), $this->psrHttpFactory()->createRequest(Request::create('/articles', 'GET')));

        $decoded = \json_decode((string) $psrResponse->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['errors'] ?? null);
        self::assertIsArray($decoded['errors'][0] ?? null);

        /** @var array<string, mixed> $error */
        $error = $decoded['errors'][0];

        return $error;
    }

    /**
     * @return array<string, mixed>
     */
    private function firstError(Response $response): array
    {
        $decoded = \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertIsArray($decoded['errors'] ?? null);
        self::assertIsArray($decoded['errors'][0] ?? null);

        /** @var array<string, mixed> $error */
        $error = $decoded['errors'][0];

        return $error;
    }

    private function serverProvider(): ServerProvider
    {
        $factory = $this->serverFactory();

        // A minimal name → factory locator over the single `default` server.
        $factories = new class ($factory) implements ContainerInterface {
            public function __construct(private readonly ServerFactory $default) {}

            public function get(string $id): mixed
            {
                if ($id === ServerProvider::DEFAULT_SERVER) {
                    return $this->default;
                }

                throw new \LogicException(\sprintf('No server factory "%s" registered.', $id));
            }

            public function has(string $id): bool
            {
                return $id === ServerProvider::DEFAULT_SERVER;
            }
        };

        return new ServerProvider($factories);
    }

    private function server(): Server
    {
        return $this->serverFactory()->create();
    }

    /**
     * A real {@see ServerFactory} over an empty resource set — enough to render
     * an error document (no resources are resolved on the error path).
     */
    private function serverFactory(): ServerFactory
    {
        $psr17 = new Psr17Factory();

        $emptyServices = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \LogicException(\sprintf('No service "%s" registered.', $id));
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        $handler = new class implements \haddowg\JsonApi\Operation\OperationHandlerInterface {
            public function handle(
                JsonApiOperationInterface $operation,
            ): DataResponse|MetaResponse|RelatedResponse|IdentifierResponse|ErrorResponse {
                throw new \LogicException('The error path never dispatches an operation.');
            }
        };

        return new ServerFactory(
            new ResourceLocator($emptyServices, []),
            $psr17,
            $psr17,
            'https://example.test',
            '1.1',
            $handler,
        );
    }

    private function psrHttpFactory(): PsrHttpFactory
    {
        $factory = new Psr17Factory();

        return new PsrHttpFactory($factory, $factory, $factory, $factory);
    }
}
