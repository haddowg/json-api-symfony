<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\OpenApi\Metadata;

use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\OpenApi\Metadata\IncludePathResolver;
use haddowg\JsonApiBundle\Server\RelationsRegistry;
use haddowg\JsonApiBundle\Server\ResourceLocator;
use haddowg\JsonApiBundle\Server\ServerFactory;
use haddowg\JsonApiBundle\Server\TypeMetadataResolver;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Characterizes the {@see IncludePathResolver} (design §4.4): it walks the relation
 * graph to derive a type's includable `?include` paths, honouring per-relation
 * includability, the max-include-depth cap, the root allow-list, and terminating on
 * cycles.
 */
#[Group('spec:openapi')]
final class IncludePathResolverTest extends TestCase
{
    #[Test]
    public function itWalksTheRelationGraphToTheDepthCap(): void
    {
        // articles -> author (people) -> company (companies); depth cap 3 allows the
        // two-hop path. A non-includable relation contributes nothing.
        [$resolver, $server] = $this->resolver(3, new IncArticle(), new IncPerson(), new IncCompany());

        self::assertEqualsCanonicalizing(
            ['author', 'author.company', 'comments'],
            $resolver->pathsFor($server, 'articles'),
        );
    }

    #[Test]
    public function aDepthCapOfOneStopsAtTheFirstHop(): void
    {
        [$resolver, $server] = $this->resolver(1, new IncArticle(), new IncPerson(), new IncCompany());

        self::assertEqualsCanonicalizing(['author', 'comments'], $resolver->pathsFor($server, 'articles'));
    }

    #[Test]
    public function aCycleTerminatesTheWalk(): void
    {
        // people <-> friends (people): a self-referential cycle yields the finite
        // prefix set, not an infinite walk, even with a generous depth cap.
        [$resolver, $server] = $this->resolver(5, new IncSelfReferentialPerson());

        $paths = $resolver->pathsFor($server, 'cyclists');
        // friends, friends.friends ... bounded by depth, never infinite. Each hop adds
        // one `.friends`; with the cycle guard the same type is not re-descended, so
        // the path set is exactly {friends}.
        self::assertSame(['friends'], $paths);
    }

    /**
     * @return array{IncludePathResolver, \haddowg\JsonApi\Server\Server}
     */
    private function resolver(int $maxDepth, AbstractResource ...$resources): array
    {
        $byClass = [];
        $classes = [];
        foreach ($resources as $resource) {
            $byClass[$resource::class] = $resource;
            $classes[] = $resource::class;
        }

        $locator = new ResourceLocator($this->container($byClass), $classes);
        $types = new TypeMetadataResolver(new RelationsRegistry($this->container([])));

        $psr17 = new Psr17Factory();
        $handler = new class implements OperationHandlerInterface {
            public function handle(
                \haddowg\JsonApi\Operation\JsonApiOperationInterface $operation,
            ): \haddowg\JsonApi\Response\DataResponse|\haddowg\JsonApi\Response\MetaResponse|\haddowg\JsonApi\Response\RelatedResponse|\haddowg\JsonApi\Response\IdentifierResponse|\haddowg\JsonApi\Response\ErrorResponse {
                throw new \LogicException('never dispatches');
            }
        };

        $server = (new ServerFactory(
            $locator,
            $psr17,
            $psr17,
            'https://api.test',
            '1.1',
            $handler,
            maxIncludeDepth: $maxDepth,
            resourceClasses: $classes,
        ))->create();

        return [new IncludePathResolver($types), $server];
    }

    /**
     * @param array<string, object> $entries
     */
    private function container(array $entries): ContainerInterface
    {
        return new class ($entries) implements ContainerInterface {
            /**
             * @param array<string, object> $entries
             */
            public function __construct(private readonly array $entries) {}

            public function get(string $id): object
            {
                return $this->entries[$id] ?? throw new \LogicException(\sprintf('No service "%s".', $id));
            }

            public function has(string $id): bool
            {
                return isset($this->entries[$id]);
            }
        };
    }
}

final class IncArticle extends AbstractResource
{
    public static string $type = 'articles';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            BelongsTo::make('author', 'people'),
            HasMany::make('comments', 'comments'),
        ];
    }
}

final class IncPerson extends AbstractResource
{
    public static string $type = 'people';

    public function fields(): array
    {
        return [
            Id::make(),
            BelongsTo::make('company', 'companies'),
            // Not includable: never advertised, never descended.
            BelongsTo::make('manager', 'people')->cannotBeIncluded(),
        ];
    }
}

final class IncCompany extends AbstractResource
{
    public static string $type = 'companies';

    public function fields(): array
    {
        return [Id::make(), Str::make('name')];
    }
}

final class IncSelfReferentialPerson extends AbstractResource
{
    public static string $type = 'cyclists';

    public function fields(): array
    {
        return [
            Id::make(),
            HasMany::make('friends', 'cyclists'),
        ];
    }
}
