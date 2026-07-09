<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationResponseInterface;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\Tag;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Pagination\CursorPaginationProfile;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Action\ActionRegistry;
use haddowg\JsonApiBundle\Action\ActionScope as BundleActionScope;
use haddowg\JsonApiBundle\OpenApi\Metadata\IncludePathResolver;
use haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource;
use haddowg\JsonApiBundle\OpenApi\Metadata\ResourceDescriptionRegistry;
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
    public function itAdvertisesTheDefaultRegisteredProfilesInCanonicalOrder(): void
    {
        // The bundle registers the three built-in profiles by default, in the canonical
        // order the OpenAPI jsonapi.profile enum lists them (bundle ADR 0117, core ADR
        // 0131). profiles() reads the live registry the ServerFactory built, not a
        // hardcoded list.
        self::assertSame(
            [CursorPaginationProfile::URI, CountableProfile::URI, RelationshipQueriesProfile::URI],
            $this->source()->forServer()->profiles(),
        );
    }

    #[Test]
    public function theGeneratedDocumentAdvertisesTheRegisteredProfilesRelatedQueryAndWithCount(): void
    {
        // Projected through the core registration-aware projector (core ADR 0131), the
        // default registration pins the jsonapi.profile enum to the three URIs in order,
        // advertises the Relationship Queries `relatedQuery` parameter (articles declares
        // relations) as a $ref to the single shared component, and the Countable
        // `?withCount` parameter (articles is countable).
        $document = (new OpenApiProjector())->project($this->source()->forServer())->toArray();

        self::assertSame(
            [CursorPaginationProfile::URI, CountableProfile::URI, RelationshipQueriesProfile::URI],
            $this->at($document, 'components', 'schemas', 'JsonApi', 'properties', 'profile', 'items', 'enum'),
        );

        $collection = $this->at($document, 'paths', '/articles', 'get');
        self::assertContains('#/components/parameters/relatedQuery', $this->parameterRefs($collection));
        self::assertContains('withCount', $this->parameterNames($collection));
        self::assertArrayHasKey('relatedQuery', $this->at($document, 'components', 'parameters'));
    }

    #[Test]
    public function trimmingTheProfilesConfigDropsThatProfilesRegistrationAndAdvertisement(): void
    {
        // A consumer trimming json_api.profiles to only Countable: the server registers
        // only that profile, so profiles() reports only it, the document's profile enum
        // shrinks to it, the relatedQuery parameter/component disappear (Relationship
        // Queries not registered), and `?withCount` survives (Countable still registered).
        $source = $this->source(profiles: [CountableProfile::class]);

        self::assertSame([CountableProfile::URI], $source->forServer()->profiles());

        $document = (new OpenApiProjector())->project($source->forServer())->toArray();

        self::assertSame(
            [CountableProfile::URI],
            $this->at($document, 'components', 'schemas', 'JsonApi', 'properties', 'profile', 'items', 'enum'),
        );

        $collection = $this->at($document, 'paths', '/articles', 'get');
        self::assertNotContains('#/components/parameters/relatedQuery', $this->parameterRefs($collection));
        self::assertArrayNotHasKey('parameters', $this->at($document, 'components'));
        self::assertContains('withCount', $this->parameterNames($collection));
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
        self::assertNull($snippets->pageSchema());
        // The default humanized tag still applies to a standalone type.
        self::assertSame(['Snippets'], $snippets->tags());
    }

    #[Test]
    public function aResourcePaginatorSelfDescribesItsPageSchema(): void
    {
        $articles = $this->typeNamed($this->source()->forServer(), 'articles');

        // ArticleResource declares no pagination(), so it resolves the server default
        // (the built-in capped PagePaginator) => a page-number/size object schema.
        $pageSchema = $articles->pageSchema();
        self::assertNotNull($pageSchema);
        self::assertSame('object', $pageSchema->get('type'));
        self::assertSame(['number', 'size'], \array_keys((array) $pageSchema->get('properties')));
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
    public function itReportsTheRelationsWithResolvedPageSchema(): void
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
        self::assertNull($byName['author']->pageSchema());
        // The to-many comments relation resolves the server-default page paginator.
        self::assertTrue($byName['comments']->isToMany());
        $commentsPage = $byName['comments']->pageSchema();
        self::assertNotNull($commentsPage);
        self::assertSame(['number', 'size'], \array_keys((array) $commentsPage->get('properties')));
    }

    #[Test]
    public function itDerivesTheIncludablePaths(): void
    {
        $articles = $this->typeNamed($this->source()->forServer(), 'articles');

        // `author` -> people (registered, no includable relations of its own). The
        // `comments` relation is includable but its `comments` type has no serializer on
        // this server (it renders links-only), so `?include=comments` could hydrate
        // nothing and the path is pruned from the advertised set (D45).
        self::assertEqualsCanonicalizing(['author'], $articles->includablePaths());
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

    // --- description overrides (bundle ADR 0092) -------------------------------

    #[Test]
    public function aTypeWithoutAnyDeclaredDescriptionLeavesTheOverridesNullForTheGeneratedDefault(): void
    {
        $articles = $this->typeNamed($this->source()->forServer(), 'articles');

        // No override declared -> null on every surface; core's projector then
        // supplies the generated default.
        self::assertNull($articles->description());
        foreach (OperationType::cases() as $operation) {
            self::assertNull($articles->operationDescription($operation));
        }
    }

    #[Test]
    public function theAttributeDescriptionRegistryOverridesTheResourceObjectAndPerOperationDescriptions(): void
    {
        $descriptions = new ResourceDescriptionRegistry([
            'articles' => [
                'description' => 'The published articles of the blog.',
                'operations' => [
                    OperationType::FetchCollection->value => 'Browse every article.',
                    OperationType::Delete->value => 'Retire an article.',
                ],
            ],
        ]);

        $articles = $this->typeNamed($this->source(descriptions: $descriptions)->forServer(), 'articles');

        self::assertSame('The published articles of the blog.', $articles->description());
        self::assertSame('Browse every article.', $articles->operationDescription(OperationType::FetchCollection));
        self::assertSame('Retire an article.', $articles->operationDescription(OperationType::Delete));
        // An operation without an override stays null (generated default applies).
        self::assertNull($articles->operationDescription(OperationType::Create));
    }

    #[Test]
    public function aResourceMethodHookWinsOverTheAttributeRegistry(): void
    {
        // The DescribedArticleResource overrides getDescription()/describeOperation();
        // the registry also carries values for the same type — the method hook wins.
        $descriptions = new ResourceDescriptionRegistry([
            'articles' => [
                'description' => 'Attribute description (should lose).',
                'operations' => [OperationType::FetchOne->value => 'Attribute operation (should lose).'],
            ],
        ]);

        $articles = $this->typeNamed(
            $this->source(descriptions: $descriptions, describedArticle: true)->forServer(),
            'articles',
        );

        self::assertSame('Method-hook resource description.', $articles->description());
        self::assertSame('Method-hook fetch-one description.', $articles->operationDescription(OperationType::FetchOne));
        // An operation the method hook does not describe falls through to the registry.
        self::assertNull($articles->operationDescription(OperationType::Create));
    }

    #[Test]
    public function aRelationCarriesItsDescribedAsAsTheRelationDescription(): void
    {
        $articles = $this->typeNamed($this->source()->forServer(), 'articles');

        $author = null;
        foreach ($articles->relations() as $relation) {
            if ($relation->name() === 'author') {
                $author = $relation;
            }
        }

        self::assertNotNull($author);
        self::assertSame('The article author', $author->description());
    }

    // --- harness --------------------------------------------------------------

    #[Test]
    #[Group('spec:openapi')]
    public function aTypeRehydratesItsDeclaredResponseOverridesElseTheCoreDefault(): void
    {
        $server = $this->source(articleResponses: [
            'Create' => [['status' => 201], ['status' => 202, 'jobType' => 'jobs']],
            'FetchOne' => [['status' => 200], ['status' => 303]],
        ])->forServer();

        $articles = $this->typeNamed($server, 'articles');

        self::assertSame(
            [201, 202],
            \array_map(static fn(OperationResponseInterface $r): int => $r->status(), $articles->responsesFor(OperationType::Create)),
        );
        self::assertSame('jobs', $articles->responsesFor(OperationType::Create)[1]->jobType());
        self::assertSame(
            [200, 303],
            \array_map(static fn(OperationResponseInterface $r): int => $r->status(), $articles->responsesFor(OperationType::FetchOne)),
        );

        // An operation with no override falls back to the core default.
        self::assertSame(
            [204],
            \array_map(static fn(OperationResponseInterface $r): int => $r->status(), $articles->responsesFor(OperationType::Delete)),
        );
    }

    /**
     * @param array<string, list<array{status: int, jobType?: string}>> $articleResponses per-operation response overrides for the `articles` descriptor (keyed by {@see OperationType::value})
     */
    /**
     * @param array<string, list<array{status: int, jobType?: string}>>               $articleResponses per-operation response overrides for the articles descriptor
     * @param list<class-string<\haddowg\JsonApi\Schema\Profile\ProfileInterface>>|null $profiles         the profiles the server registers; null uses the ServerFactory default
     */
    private function source(?ResourceSecurityRegistry $security = null, ?ServerDocumentConfig $config = null, bool $atomicEnabled = false, string $atomicPath = '/operations', ?ResourceDescriptionRegistry $descriptions = null, bool $describedArticle = false, array $articleResponses = [], ?array $profiles = null): MetadataSource
    {
        $article = $describedArticle ? new DescribedArticleResource() : new ArticleResource();
        $person = new PersonResource();
        $snippetSerializer = new SnippetSerializer();

        $resourceLocator = $this->resourceLocator([$article, $person]);
        $relations = new RelationsRegistry($this->emptyContainer());
        $types = new TypeMetadataResolver($relations);
        $idEncoders = new IdEncoderResolver($resourceLocator);

        $serverProvider = $this->serverProvider($resourceLocator, ['snippets' => $snippetSerializer], $profiles);

        $descriptors = new RouteDescriptorRegistry([
            ServerProvider::DEFAULT_SERVER => [
                'articles' => $this->descriptor('articles', true, ['Content'], responses: $articleResponses),
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
                'responds' => [['kind' => 'resource', 'type' => 'articles']],
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
            new TagNameResolver(),
            new IncludePathResolver($types),
            $security,
            $descriptions,
            $config !== null ? [ServerProvider::DEFAULT_SERVER => $config] : [],
            $atomicEnabled,
            $atomicPath,
        );
    }

    /**
     * @param list<string>                                               $tags
     * @param list<string>|null                                          $operations
     * @param array<string, list<array{status: int, jobType?: string}>>  $responses
     *
     * @return array{uriType: string, isResource: bool, hasHydrator: bool, hasRelations: bool, operations: list<string>, tags: list<string>, responses: array<string, list<array{status: int, jobType?: string}>>}
     */
    private function descriptor(string $type, bool $isResource, array $tags, ?array $operations = null, array $responses = []): array
    {
        return [
            'uriType' => $type,
            'isResource' => $isResource,
            'hasHydrator' => $isResource,
            'hasRelations' => $isResource,
            'operations' => $operations ?? ['FetchCollection', 'FetchOne', 'Create', 'Update', 'Delete'],
            'tags' => $tags,
            'responses' => $responses,
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
     * Descends a decoded document array by the given keys, asserting each hop is an
     * array (so a projection-shape drift fails loudly rather than silently).
     *
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     */
    private function at(array $node, string ...$keys): array
    {
        $cursor = $node;
        foreach ($keys as $key) {
            self::assertArrayHasKey($key, $cursor);
            $next = $cursor[$key];
            self::assertIsArray($next);
            $cursor = $next;
        }

        return $cursor;
    }

    /**
     * The `name` of each inline parameter of an operation (a component-parameter
     * reference carries a `$ref` instead — see {@see parameterRefs()}).
     *
     * @param array<array-key, mixed> $operation
     *
     * @return list<string>
     */
    private function parameterNames(array $operation): array
    {
        $parameters = $operation['parameters'] ?? [];
        self::assertIsArray($parameters);

        $names = [];
        foreach ($parameters as $parameter) {
            if (\is_array($parameter) && isset($parameter['name']) && \is_string($parameter['name'])) {
                $names[] = $parameter['name'];
            }
        }

        return $names;
    }

    /**
     * The `$ref` of each component-parameter reference of an operation.
     *
     * @param array<array-key, mixed> $operation
     *
     * @return list<string>
     */
    private function parameterRefs(array $operation): array
    {
        $parameters = $operation['parameters'] ?? [];
        self::assertIsArray($parameters);

        $refs = [];
        foreach ($parameters as $parameter) {
            if (\is_array($parameter) && isset($parameter['$ref']) && \is_string($parameter['$ref'])) {
                $refs[] = $parameter['$ref'];
            }
        }

        return $refs;
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
     * @param list<class-string<\haddowg\JsonApi\Schema\Profile\ProfileInterface>>|null $profiles the profiles the server registers; null uses the ServerFactory default (the three built-ins)
     */
    private function serverProvider(ResourceLocator $resourceLocator, array $snippetSerializers, ?array $profiles = null): ServerProvider
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
            profiles: $profiles ?? ServerFactory::DEFAULT_PROFILES,
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
 * countable to-many), countable, no client id. Non-final so the description-override
 * fixture {@see DescribedArticleResource} can reuse its field set (bundle ADR 0092).
 */
class ArticleResource extends AbstractResource
{
    public static string $type = 'articles';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            BelongsTo::make('author', 'people')->describedAs('The article author'),
            HasMany::make('comments', 'comments')->countable(),
        ];
    }

    public function isCountable(): bool
    {
        return true;
    }
}

/**
 * The `articles` type, but with the author method hooks overriding the OpenAPI
 * resource-object + fetch-one descriptions (bundle ADR 0092) — proves the method hook
 * wins over the attribute registry, and that an undescribed operation falls through.
 * The hook results are held as nullable properties so each override is genuinely a
 * `?string` (an author may return null to fall through to the registry / default).
 */
final class DescribedArticleResource extends ArticleResource
{
    /** @phpstan-ignore property.unusedType (a hook may legitimately return null to fall through) */
    private ?string $resourceDescription = 'Method-hook resource description.';

    /** @phpstan-ignore property.unusedType (a hook may legitimately return null to fall through) */
    private ?string $fetchOneDescription = 'Method-hook fetch-one description.';

    public function getDescription(): ?string
    {
        return $this->resourceDescription;
    }

    public function describeOperation(OperationType $op): ?string
    {
        return $op === OperationType::FetchOne ? $this->fetchOneDescription : null;
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
