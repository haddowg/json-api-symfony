<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Server;

use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApiBundle\Server\ResourceLocator;
use haddowg\JsonApiBundle\Server\ServerFactory;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Characterizes the multi-server {@see ServerProvider} (bundle ADR 0034): it
 * resolves a {@see Server} by name through a name → {@see ServerFactory} locator —
 * the implicit `default` and any declared named server — and treats an unknown
 * name as a wiring fault ({@see \LogicException}), not a runtime `404`.
 */
final class ServerProviderTest extends TestCase
{
    #[Test]
    #[Group('spec:multi-server')]
    public function itResolvesTheDefaultServerWhenNoNameIsGiven(): void
    {
        $default = $this->factory('https://default.test');
        $provider = new ServerProvider($this->locator(['default' => $default]));

        self::assertSame($default->create(), $provider->get());
        self::assertSame($default->create(), $provider->get('default'));
    }

    #[Test]
    #[Group('spec:multi-server')]
    public function itResolvesANamedServerFromTheLocator(): void
    {
        $default = $this->factory('https://default.test');
        $admin = $this->factory('https://admin.test');
        $provider = new ServerProvider($this->locator(['default' => $default, 'admin' => $admin]));

        // Distinct factories => distinct memoized Servers, so the name selects.
        self::assertSame($admin->create(), $provider->get('admin'));
        self::assertNotSame($default->create(), $provider->get('admin'));
    }

    #[Test]
    #[Group('spec:multi-server')]
    public function itThrowsForAnUnknownServerName(): void
    {
        $provider = new ServerProvider($this->locator(['default' => $this->factory('https://default.test')]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No JSON:API server is configured under the name "missing".');

        $provider->get('missing');
    }

    /**
     * @param array<string, ServerFactory> $factories
     */
    private function locator(array $factories): ContainerInterface
    {
        return new class ($factories) implements ContainerInterface {
            /**
             * @param array<string, ServerFactory> $factories
             */
            public function __construct(private readonly array $factories) {}

            public function get(string $id): mixed
            {
                return $this->factories[$id] ?? throw new \LogicException(\sprintf('No factory "%s".', $id));
            }

            public function has(string $id): bool
            {
                return isset($this->factories[$id]);
            }
        };
    }

    /**
     * A real {@see ServerFactory} over an empty resource set, parameterised only by
     * base URI so two instances build observably distinct Servers.
     */
    private function factory(string $baseUri): ServerFactory
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

        $handler = new class implements OperationHandlerInterface {
            public function handle(
                \haddowg\JsonApi\Operation\JsonApiOperationInterface $operation,
            ): \haddowg\JsonApi\Response\DataResponse|\haddowg\JsonApi\Response\MetaResponse|\haddowg\JsonApi\Response\RelatedResponse|\haddowg\JsonApi\Response\IdentifierResponse|\haddowg\JsonApi\Response\ErrorResponse {
                throw new \LogicException('This factory never dispatches.');
            }
        };

        return new ServerFactory(
            new ResourceLocator($emptyServices, []),
            $psr17,
            $psr17,
            $baseUri,
            '1.1',
            $handler,
        );
    }
}
