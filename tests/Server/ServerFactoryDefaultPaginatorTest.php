<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Server;

use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Pagination\OffsetPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApiBundle\Server\ResourceLocator;
use haddowg\JsonApiBundle\Server\ServerFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Characterizes how {@see ServerFactory} resolves the server-wide default
 * paginator (bundle ADR 0036): a per-server custom paginator wins over a generic
 * one, which wins over the built-in {@see PagePaginator} capped at
 * `json_api.pagination.max_per_page`, which a cap of `0` disables (no default).
 */
final class ServerFactoryDefaultPaginatorTest extends TestCase
{
    #[Test]
    #[Group('spec:fetching-pagination')]
    public function itInstallsTheBuiltInPagePaginatorCappedAtMaxPerPageByDefault(): void
    {
        $server = $this->factory(maxPerPage: 25)->create();

        $paginator = $server->defaultPaginator();
        self::assertInstanceOf(PagePaginator::class, $paginator);
        // The built-in fallback carries the configured cap, so an over-large
        // page[size] clamps to it.
        self::assertSame(25, $paginator->maxPerPage);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aMaxPerPageOfZeroInstallsNoBuiltInDefault(): void
    {
        $server = $this->factory(maxPerPage: 0)->create();

        self::assertNull($server->defaultPaginator());
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aGenericCustomPaginatorOverridesTheBuiltInDefault(): void
    {
        $generic = new OffsetPaginator();
        $server = $this->factory(maxPerPage: 100, defaultPaginator: $generic)->create();

        self::assertSame($generic, $server->defaultPaginator());
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aPerServerCustomPaginatorWinsOverTheGenericOne(): void
    {
        $perServer = new OffsetPaginator();
        $generic = new OffsetPaginator();
        $server = $this->factory(
            maxPerPage: 100,
            serverDefaultPaginator: $perServer,
            defaultPaginator: $generic,
        )->create();

        self::assertSame($perServer, $server->defaultPaginator());
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aCustomPaginatorIsUsedEvenWhenTheCapIsDisabled(): void
    {
        // max_per_page: 0 means "no built-in default", NOT "never paginate" — a
        // registered custom paginator still takes precedence.
        $custom = new OffsetPaginator();
        $server = $this->factory(maxPerPage: 0, defaultPaginator: $custom)->create();

        self::assertSame($custom, $server->defaultPaginator());
    }

    private function factory(
        int $maxPerPage = PagePaginator::DEFAULT_MAX_PER_PAGE,
        ?PaginatorInterface $serverDefaultPaginator = null,
        ?PaginatorInterface $defaultPaginator = null,
    ): ServerFactory {
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
            responseFactory: $psr17,
            streamFactory: $psr17,
            baseUri: 'https://default.test',
            version: '1.1',
            handler: $handler,
            maxPerPage: $maxPerPage,
            serverDefaultPaginator: $serverDefaultPaginator,
            defaultPaginator: $defaultPaginator,
        );
    }
}
