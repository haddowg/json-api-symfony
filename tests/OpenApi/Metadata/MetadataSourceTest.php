<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\PaginatorKind;
use haddowg\JsonApi\OpenApi\Tag;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Action\ActionRegistry;
use haddowg\JsonApiBundle\Action\ActionScope as BundleActionScope;
use haddowg\JsonApiBundle\OpenApi\Metadata\IncludePathResolver;
use haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource;
use haddowg\JsonApiBundle\OpenApi\Metadata\PaginatorKindResolver;
use haddowg\JsonApiBundle\OpenApi\Metadata\ServerDocumentConfig;
use haddowg\JsonApiBundle\OpenApi\Metadata\TagNameResolver;
use haddowg\JsonApiBundle\Security\ResourceSecurityRegistry;
use haddowg\JsonApiBundle\Server\IdEncoderResolver;
use haddowg\JsonApiBundle\Server\RelationsRegistry;
use haddowg\JsonApiBundle\Server\ResourceLocator;
use haddowg\JsonApiBundle\Server\RouteDescriptorRegistry;
use haddowg\JsonApiBundle\Server\ServerFactory;
use haddowg\JsonApiBundle\Server\ServerProvider;
use haddowg\JsonApiBundle\Server\TypeMetadataResolver;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Characterizes the {@see MetadataSource} (design §3, §4.6, §4.7, D8/D15): it
 * assembles a server's worth of OpenAPI metadata from the live registry — types,
 * operations (bundle->core enum mapping), relations, paginator kind, client-id
 * policy, tags (explicit + humanized default + definition synthesis), custom actions
 * and per-operation security intent — folding in the config-shaped document info.
 */
#[Group('spec:openapi')]
final class MetadataSourceTest extends TestCase
{
    #[Test]
    public function itBuildsTheServerInfoFromConfigWithDefaults(): void
    {
        $server = $this->source(
            config: new ServerDocumentConfig(title: 'My API', version: '2.0.0', description: 'Demo'),
        )->forServer();

        self::assertSame('My API', $server->title());
        self::assertSame('2.0.0', $server->version());
        self::assertSame('Demo', $server->description());
        self::assertSame('1.1', $server->jsonApiVersion());
        // The advertised server is derived from the per-server base URI when config
        // supplies none.
        self::assertCount(1, $server->servers());
        self::assertSame('https://api.test', $server->servers()[0]->url);
    }

    #[Test]
    public function itFallsBackToDefaultTitleAndVersionWithoutConfig(): void
    {
        $server = $this->source()->forServer();

        self::assertSame('JSON:API', $server->title());
        self::assertSame('1.0.0', $server->version());
        self::assertNull($server->description());
    }

    #[Test]
    public function itEnumeratesTheServerTypesFromTheDescriptors(): void
    {
        $types = $this->source()->forServer()->types();

        $names = \array_map(static fn($type): string => $type->type(), $types);
        self::assertSame(['articles', 'people', 'snippets'], $names);
    }

    #[Test]
    public function combinedUnionsEveryDeclaredServersTypes(): void
    {
        // With a single declared server, the combined document spans that server's
        // types (the degenerate single-server case) — proving combined() unions the
        // descriptor servers and carries the info block (D5).
        $combined = $this->source()->combined();

        $names = \array_map(static fn($type): string => $type->type(), $combined->types());
        self::assertSame(['articles', 'people', 'snippets'], $names);
        self::assertSame('JSON:API', $combined->title());
        self::assertCount(1, $combined->servers());
        self::assertSame('https://api.test', $combined->servers()[0]->url);
    }

    #[Test]
    public function itMapsTheOperationAllowListToCoreOperationTypes(): void
    {
        $articles = $this->typeNamed($this->source()->forServer(), 'articles');

        // All five CRUD operations, mapped by case name from the bundle Operation.
        self::assertEqualsCanonicalizing([
            OperationType::FetchCollection,
            OperationType::FetchOne,
            OperationType::Create,
            OperationType::Update,
            OperationType::Delete,
        ], $articles->operations());
    }

    #[Test]
    public function aStandaloneSerializerTypeHasNoFieldsAndNoOperations(): void
    {
        $snippets = $this->typeNamed($this->source()->forServer(), 'snippets');

        self::assertFalse($snippets->hasFields());
        self::assertSame([], $snippets->fields());
        self::assertSame([], $snippets->operations());
        self::assertSame(PaginatorKind::None, $snippets->paginatorKind());
        // The default humanized tag still applies to a standalone type.
        self::assertSame(['Snippets'], $snippets->tags());
    }

    #[Test]
    public function aResourcePaginatorIsDiscriminatedToItsKind(): void
    {
        $articles = $this->typeNamed($this->source()->forServer(), 'articles');

        // ArticleResource declares no pagination(), so it resolves the server default
        // (the built-in capped PagePaginator) => Page.
        self::assertSame(PaginatorKind::Page, $articles->paginatorKind());
        self::assertTrue($articles->isCountable());
    }

    #[Test]
    public function itResolvesTheExplicitTagsAndTheHumanizedDefault(): void
    {
        $serverMeta = $this->source()->forServer();

        // ArticleResource declares an explicit ['Content'] tag via its descriptor.
        self::assertSame(['Content'], $this->typeNamed($serverMeta, 'articles')->tags());
        // PersonResource declares none => humanized-type default.
        self::assertSame(['People'], $this->typeNamed($serverMeta, 'people')->tags());
    }

    #[Test]
    public function itSynthesizesTagDefinitionsForReferencedButUndefinedTags(): void
    {
        // Config defines only 'Content' (with a description); 'People'/'Snippets' are
        // referenced (defaults) but undefined => synthesized name-only, in discovery
        // order after the config definition.
        $config = new ServerDocumentConfig(
            tagDefinitions: [new Tag('Content', 'Editorial content')],
        );
        $tags = $this->source(config: $config)->forServer()->tags();

        $names = \array_map(static fn(Tag $tag): string => $tag->name, $tags);
        self::assertSame(['Content', 'People', 'Snippets'], $names);
        self::assertSame('Editorial content', $tags[0]->description);
        self::assertNull($tags[1]->description);
    }

    #[Test]
    public function itReportsTheRelationsWithResolvedPaginatorKind(): void
    {
        $articles = $this->typeNamed($this->source()->forServer(), 'articles');
        $relations = $articles->relations();

        $byName = [];
        foreach ($relations as $relation) {
            $byName[$relation->name()] = $relation;
        }

        self::assertArrayHasKey('author', $byName);
        self::assertArrayHasKey('comments', $byName);
        self::assertFalse($byName['author']->isToMany());
        self::assertSame(['people'], $byName['author']->relatedTypes());
        self::assertSame('The article author', $byName['author']->description());
        // A to-one relation has no related-collection paginator.
        self::assertSame(PaginatorKind::None, $byName['author']->paginatorKind());
        // The to-many comments relation resolves the server-default page paginator.
        self::assertTrue($byName['comments']->isToMany());
        self::assertSame(PaginatorKind::Page, $byName['comments']->paginatorKind());
    }

    #[Test]
    public function itDerivesTheIncludablePaths(): void
    {
        $articles = $this->typeNamed($this->source()->forServer(), 'articles');

        // author (-> people, which has no includable relations) + comments.
        self::assertEqualsCanonicalizing(['author', 'comments'], $articles->includablePaths());
    }

    #[Test]
    public function itReportsClientIdPolicy(): void
    {
        $serverMeta = $this->source()->forServer();

        // PersonResource allows a client id; ArticleResource does not.
        self::assertTrue($this->typeNamed($serverMeta, 'people')->allowsClientId());
        self::assertFalse($this->typeNamed($serverMeta, 'articles')->allowsClientId());
    }

    #[Test]
    public function itReportsTheCustomActionsForAType(): void
    {
        $articles = $this->typeNamed($this->source()->forServer(), 'articles');
        $actions = $articles->actions();

        self::assertCount(1, $actions);
        $action = $actions[0];
        self::assertSame('publish', $action->path());
        self::assertSame(['POST'], $action->methods());
        self::assertSame(ActionScope::Resource, $action->scope());
        self::assertSame(ActionInputMode::None, $action->inputMode());
        self::assertTrue($action->isSecured());
        // No explicit action tags => inherits the mount type's explicit resource tag.
        self::assertSame(['Content'], $action->tags());
    }

    #[Test]
    public function securedOperationsReflectTheRegistryAndTheFetchOneOnlyReadRule(): void
    {
        // articles declares security create+read; the read gates ONLY FetchOne (no
        // collection-read hook), and create gates Create.
        $registry = new ResourceSecurityRegistry([
            'articles' => ['create' => "is_granted('CREATE')", 'read' => "is_granted('VIEW')"],
        ]);

        $articles = $this->typeNamed($this->source(security: $registry)->forServer(), 'articles');

        self::assertEqualsCanonicalizing(
            [OperationType::Create, OperationType::FetchOne],
            $articles->securedOperations(),
        );
        // FetchCollection is never secured by the read expression.
        self::assertNotContains(OperationType::FetchCollection, $articles->securedOperations());
    }

    #[Test]
    public function securedOperationsAreEmptyWithoutASecurityRegistry(): void
    {
        $articles = $this->typeNamed($this->source(security: null)->forServer(), 'articles');

        self::assertSame([], $articles->securedOperations());
    }

    #[Test]
    public function atomicOperationsAreNullWhenTheExtensionIsDisabled(): void
    {
        self::assertNull($this->source(atomicEnabled: false)->forServer()->atomicOperations());
        self::assertNull($this->source(atomicEnabled: false)->combined()->atomicOperations());
    }

    #[Test]
    public function atomicOperationsCarryTheConfiguredPathAndDefaultTagWhenEnabled(): void
    {
        $atomic = $this->source(atomicEnabled: true, atomicPath: '/batch')->forServer()->atomicOperations();

        self::assertNotNull($atomic);
        self::assertSame('/batch', $atomic->path());
        self::assertSame('Atomic Operations', $atomic->tag());
        // No per-endpoint security: the atomic endpoint inherits the document-level
        // default (core's projector falls back when security() is empty).
        self::assertSame([], $atomic->security());
    }

    #[Test]
    public function combinedCarriesTheAtomicMetadataWhenEnabled(): void
    {
        // The extension is global, so the combined document carries it too.
        $atomic = $this->source(atomicEnabled: true)->combined()->atomicOperations();

        self::assertNotNull($atomic);
        self::assertSame('/operations', $atomic->path());
    }

    // --- harness --------------------------------------------------------------

    private function source(?ResourceSecurityRegistry $security = null, ?ServerDocumentConfig $config = null, bool $atomicEnabled = false, string $atomicPath = '/operations'): MetadataSource
    {
        $article = new ArticleResource();
        $person = new PersonResource();
        $snippetSerializer = new SnippetSerializer();

        $resourceLocator = $this->resourceLocator([$article, $person]);
        $relations = new RelationsRegistry($this->emptyContainer());
        $types = new TypeMetadataResolver($relations);
        $idEncoders = new IdEncoderResolver($resourceLocator);

        $serverProvider = $this->serverProvider($resourceLocator, ['snippets' => $snippetSerializer]);

        $descriptors = new RouteDescriptorRegistry([
            ServerProvider::DEFAULT_SERVER => [
                'articles' => $this->descriptor('articles', true, ['Content']),
                'people' => $this->descriptor('people', true, []),
                'snippets' => $this->descriptor('snippets', false, [], operations: []),
            ],
        ]);

        $actions = new ActionRegistry($this->emptyContainer(), [
            ActionRegistry::key(ServerProvider::DEFAULT_SERVER, 'articles', BundleActionScope::Resource, 'publish') => [
                'type' => 'articles',
                'path' => 'publish',
                'methods' => ['POST'],
                'scope' => BundleActionScope::Resource->name,
                'input' => ActionInput::None->name,
                'inputType' => 'articles',
                'outputType' => 'articles',
                'security' => "is_granted('PUBLISH')",
                'handlerServiceId' => 'app.publish_handler',
                'server' => ServerProvider::DEFAULT_SERVER,
                // No explicit action tags: inherit the mount resource's tags.
                'tags' => '',
            ],
        ]);

        return new MetadataSource(
            $serverProvider,
            $descriptors,
            $types,
            $idEncoders,
            $actions,
            new PaginatorKindResolver(),
            new TagNameResolver(),
            new IncludePathResolver($types),
            $security,
            $config !== null ? [ServerProvider::DEFAULT_SERVER => $config] : [],
            $atomicEnabled,
            $atomicPath,
        );
    }

    /**
     * @param list<string>      $tags
     * @param list<string>|null $operations
     *
     * @return array{uriType: string, isResource: bool, hasHydrator: bool, hasRelations: bool, operations: list<string>, tags: list<string>}
     */
    private function descriptor(string $type, bool $isResource, array $tags, ?array $operations = null): array
    {
        return [
            'uriType' => $type,
            'isResource' => $isResource,
            'hasHydrator' => $isResource,
            'hasRelations' => $isResource,
            'operations' => $operations ?? ['FetchCollection', 'FetchOne', 'Create', 'Update', 'Delete'],
            'tags' => $tags,
        ];
    }

    private function typeNamed(\haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface $server, string $type): \haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface
    {
        foreach ($server->types() as $candidate) {
            if ($candidate->type() === $type) {
                return $candidate;
            }
        }

        self::fail(\sprintf('No type "%s" in the server metadata.', $type));
    }

    /**
     * @param list<AbstractResource> $resources
     */
    private function resourceLocator(array $resources): ResourceLocator
    {
        $byClass = [];
        $classes = [];
        foreach ($resources as $resource) {
            $byClass[$resource::class] = $resource;
            $classes[] = $resource::class;
        }

        return new ResourceLocator($this->container($byClass), $classes);
    }

    /**
     * @param array<string, \haddowg\JsonApi\Serializer\SerializerInterface> $snippetSerializers a type -> standalone serializer map registered on the server
     */
    private function serverProvider(ResourceLocator $resourceLocator, array $snippetSerializers): ServerProvider
    {
        $psr17 = new Psr17Factory();

        $handler = new class implements OperationHandlerInterface {
            public function handle(
                \haddowg\JsonApi\Operation\JsonApiOperationInterface $operation,
            ): \haddowg\JsonApi\Response\DataResponse|\haddowg\JsonApi\Response\MetaResponse|\haddowg\JsonApi\Response\RelatedResponse|\haddowg\JsonApi\Response\IdentifierResponse|\haddowg\JsonApi\Response\ErrorResponse {
                throw new \LogicException('never dispatches');
            }
        };

        // The locator must resolve the standalone serializer class-strings too, so
        // core can register the serializer/hydrator pair.
        $standalone = [];
        foreach ($snippetSerializers as $serializer) {
            $standalone[$serializer::class] = $serializer;
        }

        $factory = new ServerFactory(
            $this->mergeLocator($resourceLocator, $standalone),
            $psr17,
            $psr17,
            'https://api.test',
            '1.1',
            $handler,
            resourceClasses: $resourceLocator->classes(),
            standaloneSerializers: \array_map(static fn(\haddowg\JsonApi\Serializer\SerializerInterface $s): string => $s::class, $snippetSerializers),
        );

        return new ServerProvider($this->container([ServerProvider::DEFAULT_SERVER => $factory]));
    }

    /**
     * A ResourceLocator whose container also resolves the standalone serializer
     * class-strings (the server registers them by class-string through the same
     * resolver).
     *
     * @param array<class-string, object> $extra
     */
    private function mergeLocator(ResourceLocator $base, array $extra): ResourceLocator
    {
        $byClass = $extra;
        foreach ($base->classes() as $class) {
            $byClass[$class] = $base->get($class);
        }

        return new ResourceLocator($this->container($byClass), $base->classes());
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

    private function emptyContainer(): ContainerInterface
    {
        return $this->container([]);
    }
}

/**
 * A resource exposing two relations (one to-one with a description, one to-many
 * countable to-many), countable, no client id.
 */
final class ArticleResource extends AbstractResource
{
    public static string $type = 'articles';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            BelongsTo::make('author', 'people')->description('The article author'),
            HasMany::make('comments', 'comments')->countable(),
        ];
    }

    public function isCountable(): bool
    {
        return true;
    }
}

/**
 * A resource that allows a client-supplied id and declares no relations.
 */
final class PersonResource extends AbstractResource
{
    public static string $type = 'people';

    public function fields(): array
    {
        return [
            Id::make()->allowClientId(),
            Str::make('name'),
        ];
    }
}

/**
 * A standalone serializer (no resource) for a serialize-only `snippets` type.
 */
final class SnippetSerializer implements \haddowg\JsonApi\Serializer\SerializerInterface
{
    public function getType(mixed $object): string
    {
        return 'snippets';
    }

    public function getId(mixed $object): string
    {
        return '1';
    }

    public function getAttributes(mixed $object, \haddowg\JsonApi\Request\JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getRelationships(mixed $object, \haddowg\JsonApi\Request\JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getLinks(mixed $object, \haddowg\JsonApi\Request\JsonApiRequestInterface $request): ?\haddowg\JsonApi\Schema\Link\ResourceLinks
    {
        return null;
    }

    public function getMeta(mixed $object, \haddowg\JsonApi\Request\JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }
}
