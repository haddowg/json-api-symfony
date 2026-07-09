<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Server;

use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Pagination\CursorPaginationProfile;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\ProfileInterface;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\Server\ResourceLocator;
use haddowg\JsonApiBundle\Server\ServerFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Characterizes how {@see ServerFactory} registers the server's JSON:API profiles
 * (bundle ADR 0117): data-driven from `json_api.profiles`, defaulting to the three
 * built-ins in the canonical order the OpenAPI `jsonapi.profile` enum lists them
 * (core ADR 0131), and trimmable/overridable by a consumer. Registration order is
 * significant — it is the order the enum is generated in and must match the Laravel
 * adapter's default for cross-adapter byte-parity.
 */
#[Group('spec:extensions-and-profiles')]
final class ServerFactoryProfilesTest extends TestCase
{
    #[Test]
    public function itRegistersTheThreeBuiltInProfilesInCanonicalOrderByDefault(): void
    {
        self::assertSame(
            [CursorPaginationProfile::URI, CountableProfile::URI, RelationshipQueriesProfile::URI],
            $this->registeredUris($this->factory()),
        );
    }

    #[Test]
    public function trimmingTheProfilesConfigLeavesOnlyTheListedProfilesRegistered(): void
    {
        // A consumer opting out of cursor + relationship-queries, keeping only Countable.
        self::assertSame(
            [CountableProfile::URI],
            $this->registeredUris($this->factory(profiles: [CountableProfile::class])),
        );
    }

    #[Test]
    public function anEmptyProfilesConfigRegistersNoProfiles(): void
    {
        self::assertSame([], $this->registeredUris($this->factory(profiles: [])));
    }

    #[Test]
    public function theRegistrationOrderFollowsTheConfiguredOrder(): void
    {
        // Order is significant (it is the byte-order of the jsonapi.profile enum), so a
        // reordered config yields a reordered registry — not a normalized set.
        self::assertSame(
            [RelationshipQueriesProfile::URI, CursorPaginationProfile::URI, CountableProfile::URI],
            $this->registeredUris($this->factory(profiles: [
                RelationshipQueriesProfile::class,
                CursorPaginationProfile::class,
                CountableProfile::class,
            ])),
        );
    }

    /**
     * The URIs of the profiles the built Server registered, in registration order.
     *
     * @return list<string>
     */
    private function registeredUris(ServerFactory $factory): array
    {
        return \array_map(
            static fn(ProfileInterface $profile): string => $profile->uri(),
            $factory->create()->profiles()->all(),
        );
    }

    /**
     * @param list<class-string<ProfileInterface>>|null $profiles null uses the ServerFactory default
     */
    private function factory(?array $profiles = null): ServerFactory
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
            responseFactory: $psr17,
            streamFactory: $psr17,
            baseUri: 'https://default.test',
            version: '1.1',
            handler: $handler,
            profiles: $profiles ?? ServerFactory::DEFAULT_PROFILES,
        );
    }
}
