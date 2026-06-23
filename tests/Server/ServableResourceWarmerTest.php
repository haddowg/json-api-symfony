<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Server;

use haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Operation\Operation;
use haddowg\JsonApiBundle\Server\RelationsRegistry;
use haddowg\JsonApiBundle\Server\RouteDescriptorRegistry;
use haddowg\JsonApiBundle\Server\ServableResourceWarmer;
use haddowg\JsonApiBundle\Server\ServerProvider;
use haddowg\JsonApiBundle\Server\TypeMetadataResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The servability guard's throw paths: a routed read operation with no DataProvider,
 * and a routed write operation with no DataPersister, must each fail `cache:warmup`
 * (the build) rather than surface as a runtime 500. The Id-cardinality branch resolves
 * the server lazily and is exercised by the full functional suite (every booted kernel
 * runs this warmer); these unit cases pin the servability branches without a kernel.
 */
#[CoversClass(ServableResourceWarmer::class)]
final class ServableResourceWarmerTest extends TestCase
{
    #[Test]
    public function aReadRouteWithNoProviderFailsWarmUp(): void
    {
        $warmer = $this->warmer([Operation::FetchCollection->value], providerTypes: []);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/"memos".*no DataProvider/');
        $warmer->warmUp('/tmp');
    }

    #[Test]
    public function aWriteRouteWithNoPersisterFailsWarmUp(): void
    {
        // A provider supports the read side, so the failure is specifically the missing
        // persister for the create operation — not the provider.
        $warmer = $this->warmer([Operation::FetchCollection->value, Operation::Create->value], providerTypes: ['memos']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/"memos".*no DataPersister/');
        $warmer->warmUp('/tmp');
    }

    /**
     * Builds the warmer over a single `memos` type with the given exposed operations and
     * the set of types an in-memory provider supports; no persister is ever registered,
     * and the server locator throws if reached (the servability branches fail first).
     *
     * @param list<string> $operations    the type's exposed CRUD operation allow-list
     * @param list<string> $providerTypes the types an in-memory provider supports
     */
    private function warmer(array $operations, array $providerTypes): ServableResourceWarmer
    {
        $descriptors = new RouteDescriptorRegistry([
            'default' => [
                'memos' => [
                    'uriType' => 'memos',
                    'isResource' => true,
                    'hasHydrator' => true,
                    'hasRelations' => false,
                    'operations' => $operations,
                    'tags' => [],
                ],
            ],
        ]);

        $providers = new DataProviderRegistry(\array_map(
            static fn(string $type): InMemoryDataProvider => new InMemoryDataProvider($type, [], static fn(object $item): string => ''),
            $providerTypes,
        ));

        return new ServableResourceWarmer(
            new ServerProvider($this->throwingLocator()),
            $descriptors,
            $providers,
            new DataPersisterRegistry([]),
            new TypeMetadataResolver(new RelationsRegistry($this->throwingLocator())),
            ['default'],
        );
    }

    private function throwingLocator(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \LogicException('The server should not be resolved on a servability failure.');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }
}
