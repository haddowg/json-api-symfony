<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta\TrMetaRecorder;
use haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta\TrNoteResource;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * A write's top-level `meta` and its resource object's `data.meta` are validated against
 * what the type declares, as attributes are, and their typed values reach the write's
 * hooks. A resource object renders its declared meta, a relationship write may answer a
 * meta-only document and a 202 types its job document's meta. Debug mode refuses a
 * rendered meta that does not match its declaration.
 */
abstract class TypedRequestMetaConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string ATOMIC = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';

    protected function setUp(): void
    {
        parent::setUp();
        TrMetaRecorder::$seen = [];
        TrMetaRecorder::$raw = [];
        TrNoteResource::$deletedBecause = null;
    }

    #[Test]
    public function aCreatesRequestMetaReachesItsHooksTyped(): void
    {
        $response = $this->handle('/tr-notes', 'POST', [
            'data' => ['type' => 'tr-notes', 'attributes' => ['title' => 'Draft'], 'meta' => ['source' => 'import', 'label' => 'blue']],
            'meta' => ['idempotencyKey' => 'k-1', 'scheduledAt' => '2026-11-05T09:42:00+00:00', 'attempt' => 5.0],
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('k-1', self::at($data, 'attributes', 'createdWith'), 'the create hook read the key through HookContext::writtenMeta()');
        self::assertSame(['revision' => 1], $data['meta'] ?? null, 'only the read-only member renders');

        $seen = TrMetaRecorder::$seen['tr-notes:BeforeCreateEvent'] ?? null;
        self::assertIsArray($seen);
        self::assertSame('k-1', $seen['document']['idempotencyKey'] ?? null);
        self::assertSame(5, $seen['document']['attempt'] ?? null, 'a whole 5.0 is normalised to its declared integer');
        $scheduled = $seen['document']['scheduledAt'] ?? null;
        self::assertInstanceOf(\DateTimeImmutable::class, $scheduled);
        self::assertSame('2026-11-05T09:42:00+00:00', $scheduled->format(\DATE_ATOM));
        self::assertSame(['source' => 'import', 'label' => 'blue'], $seen['resource']);
        self::assertSame($seen, TrMetaRecorder::$seen['tr-notes:AfterCreateEvent'] ?? null);

        // The fillUsing() hook stored the write-only `source`; `label` stayed on the meta.
        $id = $data['id'] ?? null;
        self::assertIsString($id);
        self::assertSame('import', $this->sourceOf($id));
    }

    #[Test]
    public function anUndeclaredOrMistypedMemberIs422(): void
    {
        $create = static fn(array $meta, array $resourceMeta = []): array => [
            'data' => ['type' => 'tr-notes', 'attributes' => ['title' => 'Draft'], ...($resourceMeta === [] ? [] : ['meta' => $resourceMeta])],
            'meta' => $meta,
        ];

        self::assertSame(['/meta/bogus' => 'The member "bogus" is not declared.'], $this->violations($this->handle('/tr-notes', 'POST', $create(['idempotencyKey' => 'k', 'bogus' => 1]))));
        self::assertSame(['/data/meta/bogus' => 'The member "bogus" is not declared.'], $this->violations($this->handle('/tr-notes', 'POST', $create(['idempotencyKey' => 'k'], ['bogus' => 1]))));
        self::assertSame(['/meta/attempt'], \array_keys($this->violations($this->handle('/tr-notes', 'POST', $create(['idempotencyKey' => 'k', 'attempt' => 'five'])))));
        self::assertSame(['/data/meta/source'], \array_keys($this->violations($this->handle('/tr-notes', 'POST', $create(['idempotencyKey' => 'k'], ['source' => 5])))));
        self::assertSame(['/meta/scheduledAt'], \array_keys($this->violations($this->handle('/tr-notes', 'POST', $create(['idempotencyKey' => 'k', 'scheduledAt' => '2026-11-05 09:42'])))));
        self::assertSame(['/meta/idempotencyKey'], \array_keys($this->violations($this->handle('/tr-notes', 'POST', $create(['attempt' => 1])))));
        self::assertSame(['/meta/idempotencyKey'], \array_keys($this->violations($this->handle('/tr-notes', 'POST', ['data' => ['type' => 'tr-notes', 'attributes' => ['title' => 'No meta']]]))));
        // The batch-only member is undeclared outside a batch.
        self::assertSame(['/meta/batchTag'], \array_keys($this->violations($this->handle('/tr-notes', 'POST', $create(['idempotencyKey' => 'k', 'batchTag' => 'b'])))));
    }

    #[Test]
    public function aReadOnlyResourceMetaMemberIsIgnoredOnAWrite(): void
    {
        $response = $this->handle('/tr-notes/1', 'PATCH', ['data' => ['type' => 'tr-notes', 'id' => '1', 'attributes' => ['title' => 'Plan again'], 'meta' => ['revision' => 7]], 'meta' => ['reason' => 'typo']]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['revision' => 1], self::at($this->decode($response), 'data', 'meta'));
        self::assertSame(['document' => ['reason' => 'typo'], 'resource' => []], TrMetaRecorder::$seen['tr-notes:BeforeUpdateEvent'] ?? null);
    }

    #[Test]
    public function aDeleteTakesAMetaOnlyBody(): void
    {
        self::assertSame(['/meta/reason'], \array_keys($this->violations($this->handle('/tr-notes/2', 'DELETE'))));

        self::assertNull(TrNoteResource::$deletedBecause, 'an invalid body never reaches the beforeDelete() hook');

        $response = $this->handle('/tr-notes/2', 'DELETE', ['meta' => ['reason' => 'duplicate']]);
        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['reason' => 'duplicate'], TrMetaRecorder::$seen['tr-notes:BeforeDeleteEvent']['document'] ?? null);
        self::assertSame('duplicate', TrNoteResource::$deletedBecause, 'the beforeDelete() hook reads the validated meta');
    }

    #[Test]
    public function aForbiddenDeleteIsRefusedBeforeItsMetaIsValidated(): void
    {
        // A signed-in caller, so a refusal is a 403 rather than a 401.
        $tokens = static::getContainer()->get('security.token_storage');
        self::assertInstanceOf(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class, $tokens);
        $tokens->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(new \Symfony\Component\Security\Core\User\InMemoryUser('ada', null, ['ROLE_USER']), 'main', ['ROLE_USER']));

        // Note 1's security expression refuses its delete, whose meta is missing the
        // required reason and names an undeclared member.
        $denied = $this->handle('/tr-notes/1', 'DELETE', ['meta' => ['bogus' => true]]);
        self::assertSame(403, $denied->getStatusCode(), (string) $denied->getContent());
        self::assertNull(TrNoteResource::$deletedBecause);

        self::assertSame(['/meta/bogus', '/meta/reason'], $this->sortedKeys($this->violations($this->handle('/tr-notes/2', 'DELETE', ['meta' => ['bogus' => true]]))));
    }

    /**
     * @param array<string, mixed> $violations
     *
     * @return list<string>
     */
    private function sortedKeys(array $violations): array
    {
        $keys = \array_keys($violations);
        \sort($keys);

        return $keys;
    }

    #[Test]
    public function eachRelationshipWriteTakesTheRelationsMeta(): void
    {
        $patch = $this->handle('/tr-notes/1/relationships/items', 'PATCH', ['data' => [['type' => 'tr-items', 'id' => '2']], 'meta' => ['reason' => 'swap']]);
        self::assertSame(200, $patch->getStatusCode(), (string) $patch->getContent());
        self::assertSame([['type' => 'tr-items', 'id' => '2']], $this->decode($patch)['data'] ?? null, 'a replace declares no document meta, so it answers its linkage');
        self::assertSame(['reason' => 'swap'], TrMetaRecorder::$seen['tr-notes:BeforeRelationshipMutateEvent']['document'] ?? null);

        // Adding answers the meta-only document its relationshipDocumentMeta() declares.
        $add = $this->handle('/tr-notes/1/relationships/items', 'POST', ['data' => [['type' => 'tr-items', 'id' => '1']], 'meta' => ['reason' => 'more']]);
        self::assertSame(200, $add->getStatusCode(), (string) $add->getContent());
        $document = $this->decode($add);
        self::assertSame(['count' => 2], $document['meta'] ?? null);
        self::assertArrayNotHasKey('data', $document);

        $remove = $this->handle('/tr-notes/1/relationships/items', 'DELETE', ['data' => [['type' => 'tr-items', 'id' => '1']], 'meta' => ['reason' => 'less']]);
        self::assertSame(200, $remove->getStatusCode(), (string) $remove->getContent());
        self::assertSame(['reason' => 'less'], TrMetaRecorder::$seen['tr-notes:BeforeRelationshipMutateEvent']['document'] ?? null);

        self::assertSame(['/meta/bogus' => 'The member "bogus" is not declared.'], $this->violations($this->handle('/tr-notes/1/relationships/items', 'POST', ['data' => [['type' => 'tr-items', 'id' => '2']], 'meta' => ['bogus' => true]])));
    }

    #[Test]
    public function anOpenResourceMetaTakesAnyMemberItsValueFieldAccepts(): void
    {
        $response = $this->handle('/tr-items', 'POST', ['data' => ['type' => 'tr-items', 'attributes' => ['name' => 'Nib'], 'meta' => ['floor' => 3.0, 'shelf' => 2]]]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['floor' => 3, 'shelf' => 2], self::at($this->decode($response), 'data', 'meta'));

        self::assertSame(['/data/meta/floor'], \array_keys($this->violations($this->handle('/tr-items', 'POST', ['data' => ['type' => 'tr-items', 'attributes' => ['name' => 'Nib'], 'meta' => ['floor' => 'third']]]))));
    }

    #[Test]
    public function aLinkageIdentifierCarriesNoResourceMeta(): void
    {
        $document = $this->decode($this->handle('/tr-notes/1?include=items'));
        $linkage = self::at($document, 'data', 'relationships', 'items', 'data');
        self::assertSame([['type' => 'tr-items', 'id' => '1']], $linkage);
        $included = $document['included'] ?? null;
        self::assertIsArray($included);
        self::assertSame(['size' => 3], self::at($included, 0, 'meta'), 'the full resource object renders its declared meta');
    }

    #[Test]
    public function anActionsRequestMetaDependsOnItsMethod(): void
    {
        self::assertSame(['silent' => true, 'method' => 'POST'], $this->decode($this->handle('/tr-notes/1/-actions/archive', 'POST', ['meta' => ['silent' => true]]))['meta'] ?? null);
        self::assertSame(['silent' => false, 'method' => 'GET'], $this->decode($this->handle('/tr-notes/1/-actions/archive'))['meta'] ?? null);
        self::assertSame(['/meta/silent'], \array_keys($this->violations($this->handle('/tr-notes/1/-actions/archive', 'POST', ['meta' => ['silent' => 'yes']]))));
        self::assertSame(['/meta/bogus' => 'The member "bogus" is not declared.'], $this->violations($this->handle('/tr-notes/1/-actions/archive', 'POST', ['meta' => ['bogus' => 1]])));
    }

    #[Test]
    public function aDocumentActionsDataMetaIsTheInputTypesResourceMeta(): void
    {
        $response = $this->handle('/tr-items/-actions/import', 'POST', ['data' => ['type' => 'tr-items', 'attributes' => ['name' => 'Quill'], 'meta' => ['aisle' => 4]]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['aisle' => 4], self::at($this->decode($response), 'data', 'meta'));

        self::assertSame(['/data/meta/aisle'], \array_keys($this->violations($this->handle('/tr-items/-actions/import', 'POST', ['data' => ['type' => 'tr-items', 'attributes' => ['name' => 'Quill'], 'meta' => ['aisle' => 'four']]]))));
    }

    #[Test]
    public function eachAtomicOperationsMetaIsValidatedWithTheAtomicFlag(): void
    {
        $ok = $this->atomic(['atomic:operations' => [
            ['op' => 'add', 'data' => ['type' => 'tr-notes', 'attributes' => ['title' => 'Batch']], 'meta' => ['idempotencyKey' => 'k', 'batchTag' => 'b']],
            ['op' => 'add', 'ref' => ['type' => 'tr-notes', 'id' => '1', 'relationship' => 'items'], 'data' => [['type' => 'tr-items', 'id' => '2']]],
        ], 'meta' => ['anything' => ['goes' => true]]]);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getContent());
        self::assertSame('b', TrMetaRecorder::$seen['tr-notes:BeforeCreateEvent']['document']['batchTag'] ?? null);
        $results = $this->decode($ok)['atomic:results'] ?? null;
        self::assertIsArray($results);
        self::assertSame(['count' => 2], self::at($results, 1, 'meta'), 'a relationship add answers its meta-only result');

        self::assertSame(['/atomic:operations/0/meta/batchTag'], \array_keys($this->violations($this->atomic(['atomic:operations' => [
            ['op' => 'add', 'data' => ['type' => 'tr-notes', 'attributes' => ['title' => 'Batch']], 'meta' => ['idempotencyKey' => 'k']],
        ]]))));
        self::assertSame(['/atomic:operations/0/data/meta/bogus' => 'The member "bogus" is not declared.'], $this->violations($this->atomic(['atomic:operations' => [
            ['op' => 'add', 'data' => ['type' => 'tr-notes', 'attributes' => ['title' => 'Batch'], 'meta' => ['bogus' => 1]], 'meta' => ['idempotencyKey' => 'k', 'batchTag' => 'b']],
        ]])));
    }

    #[Test]
    public function anAtomicOperationsDataMetaFillsThroughTheAtomicDeclaration(): void
    {
        $ok = $this->atomic(['atomic:operations' => [
            ['op' => 'add', 'data' => ['type' => 'tr-notes', 'attributes' => ['title' => 'Batch'], 'meta' => ['batchSource' => 'feed']], 'meta' => ['idempotencyKey' => 'k', 'batchTag' => 'b']],
        ]]);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getContent());
        $id = self::at($this->decode($ok), 'atomic:results', 0, 'data', 'id');
        self::assertIsString($id);
        self::assertSame('feed', $this->sourceOf($id), 'the atomic declaration\'s fill hook stored it');

        // The standalone member is not part of the atomic shape.
        self::assertSame(['/atomic:operations/0/data/meta/source' => 'The member "source" is not declared.'], $this->violations($this->atomic(['atomic:operations' => [
            ['op' => 'add', 'data' => ['type' => 'tr-notes', 'attributes' => ['title' => 'Batch'], 'meta' => ['source' => 'feed']], 'meta' => ['idempotencyKey' => 'k', 'batchTag' => 'b']],
        ]])));
        // And the atomic member is not part of the standalone one.
        self::assertSame(['/data/meta/batchSource' => 'The member "batchSource" is not declared.'], $this->violations($this->handle('/tr-notes', 'POST', [
            'data' => ['type' => 'tr-notes', 'attributes' => ['title' => 'Draft'], 'meta' => ['batchSource' => 'feed']],
            'meta' => ['idempotencyKey' => 'k'],
        ])));
    }

    #[Test]
    public function aForbiddenUpdateIsRefusedBeforeItsMetaIsValidated(): void
    {
        $invalid = static fn(string $id): array => ['data' => ['type' => 'tr-notes', 'id' => $id, 'attributes' => ['title' => 'Again'], 'meta' => ['bogus' => 1]], 'meta' => ['reason' => 5]];

        // A signed-in caller, so a refusal is a 403 rather than a 401.
        $tokens = static::getContainer()->get('security.token_storage');
        self::assertInstanceOf(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class, $tokens);
        $tokens->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(new \Symfony\Component\Security\Core\User\InMemoryUser('ada', null, ['ROLE_USER']), 'main', ['ROLE_USER']));

        // Note 2's security expression refuses its update, before its meta is validated.
        $denied = $this->handle('/tr-notes/2', 'PATCH', $invalid('2'));
        self::assertSame(403, $denied->getStatusCode(), (string) $denied->getContent());

        self::assertSame(['/meta/reason', '/data/meta/bogus'], \array_keys($this->violations($this->handle('/tr-notes/1', 'PATCH', $invalid('1')))));
    }

    #[Test]
    public function aWritesAcceptedJobDocumentCarriesItsDeclaredMeta(): void
    {
        $export = $this->handle('/tr-exports', 'POST', ['data' => ['type' => 'tr-exports', 'attributes' => ['name' => 'All']]]);
        self::assertSame(202, $export->getStatusCode(), (string) $export->getContent());
        self::assertSame(['jobStatus' => 'queued'], $this->decode($export)['meta'] ?? null);

        $queue = $this->handle('/tr-notes/1/-actions/queue', 'POST');
        self::assertSame(202, $queue->getStatusCode(), (string) $queue->getContent());
        $meta = $this->decode($queue)['meta'] ?? null;
        self::assertIsArray($meta);
        \ksort($meta);
        self::assertSame(['queue' => 'default', 'queuedAs' => 'job-9'], $meta);
    }

    #[Test]
    public function aTypeDeclaringNothingStaysOpen(): void
    {
        $response = $this->handle('/tr-plains', 'POST', ['data' => ['type' => 'tr-plains', 'attributes' => ['name' => 'Free'], 'meta' => ['anything' => 1]], 'meta' => ['whatever' => ['x' => 1]]]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['free' => true], self::at($this->decode($response), 'data', 'meta'));

        // Nothing declared for the create, so its typed top-level meta is empty, as an
        // action's requestMeta() is, while the raw meta stays on the request.
        self::assertSame([], TrMetaRecorder::$seen['tr-plains:BeforeCreateEvent']['document'] ?? null);
        self::assertSame(['whatever' => ['x' => 1]], TrMetaRecorder::$raw['tr-plains:BeforeCreateEvent'] ?? null);

        $this->bootInDebug();
        self::assertSame(200, $this->handle('/tr-plains/1')->getStatusCode(), 'getMeta() of a type declaring no resource meta is not checked');
    }

    #[Test]
    public function productionRendersAMismatchedMetaAsItIs(): void
    {
        self::assertSame(['revision' => 'many'], self::at($this->decode($this->handle('/tr-notes/2')), 'data', 'meta'));
        self::assertSame(['uses' => 2, 'bogus' => true], self::at($this->decode($this->handle('/tr-labels/urgent', extraServer: ['HTTP_X_BOGUS_META' => '1'])), 'data', 'meta'));
        self::assertSame(200, $this->handle('/tr-notes/1?include=lead', extraServer: ['HTTP_X_BOGUS_META' => '1'])->getStatusCode());
    }

    #[Test]
    public function debugRefusesARenderedMetaThatDoesNotMatch(): void
    {
        $this->bootInDebug();

        self::assertSame(200, $this->handle('/tr-notes/1')->getStatusCode());
        self::assertSame(['uses' => 2], self::at($this->decode($this->handle('/tr-labels/urgent')), 'data', 'meta'));

        $wrongType = $this->handle('/tr-notes/2');
        self::assertSame(500, $wrongType->getStatusCode());
        self::assertStringContainsString('the member \"revision\" is string, not integer', (string) $wrongType->getContent());

        $undeclared = $this->handle('/tr-labels/urgent', extraServer: ['HTTP_X_BOGUS_META' => '1']);
        self::assertSame(500, $undeclared->getStatusCode());
        self::assertStringContainsString('the member \"bogus\" is not declared', (string) $undeclared->getContent());

        $identifier = $this->handle('/tr-notes/1?include=lead', extraServer: ['HTTP_X_BOGUS_META' => '1']);
        self::assertSame(500, $identifier->getStatusCode());
        self::assertStringContainsString('relationship \"lead\": the member \"bogus\" is not declared', (string) $identifier->getContent());
    }

    #[Test]
    public function theGeneratedDocumentTypesTheDeclaredMeta(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $document = \json_decode((string) \json_encode($factory->forServer()->toArray()), true);
        self::assertIsArray($document);

        foreach ([
            ['/tr-notes', 'post', 'TrNotesCreateRequestDocument'],
            ['/tr-notes/{id}', 'patch', 'TrNotesUpdateRequestDocument'],
            ['/tr-notes/{id}', 'delete', 'TrNotesDeleteRequestDocument'],
            ['/tr-notes/{id}/relationships/items', 'post', 'TrNotesItemsAddRelationshipRequestDocument'],
            ['/tr-notes/{id}/-actions/archive', 'post', 'TrNotesArchiveActionPostRequestDocument'],
        ] as [$path, $method, $component]) {
            self::assertSame(['$ref' => '#/components/schemas/' . $component], self::at($document, 'paths', $path, $method, 'requestBody', 'content', 'application/vnd.api+json', 'schema'), $method . ' ' . $path);
            self::assertTrue(self::at($document, 'components', 'schemas', $component, 'x-request-meta'), $component);
        }
        self::assertNull(self::at($document, 'components', 'schemas', 'TrNotesArchiveActionGetRequestDocument'));

        // A delete taking request meta validates its body, so it answers 422; one taking none does not.
        self::assertNotNull(self::at($document, 'paths', '/tr-notes/{id}', 'delete', 'responses', '422'));
        self::assertNull(self::at($document, 'paths', '/tr-plains/{id}', 'delete', 'responses', '422'));
        self::assertSame(['$ref' => '#/components/examples/VALIDATION_FAILED'], self::at($document, 'paths', '/tr-notes/{id}', 'delete', 'responses', '422', 'content', 'application/vnd.api+json', 'examples', 'VALIDATION_FAILED'));
        self::assertSame(['$ref' => '#/components/examples/VALIDATION_FAILED'], self::at($document, 'paths', '/tr-notes/{id}/-actions/archive', 'post', 'responses', '422', 'content', 'application/vnd.api+json', 'examples', 'VALIDATION_FAILED'));

        foreach (['TrNotesResourceMeta', 'TrNotesCreateResourceMeta', 'TrNotesUpdateResourceMeta', 'TrItemsResourceMeta'] as $component) {
            self::assertTrue(self::at($document, 'components', 'schemas', $component, 'x-resource-meta'), $component);
        }
        self::assertSame(['$ref' => '#/components/schemas/TrNotesResourceMeta'], self::at($document, 'components', 'schemas', 'TrNotesResource', 'properties', 'meta'));
        self::assertFalse(self::at($document, 'components', 'schemas', 'TrNotesResourceMeta', 'additionalProperties'));
        self::assertSame(['type' => ['integer', 'null']], self::at($document, 'components', 'schemas', 'TrItemsResourceMeta', 'additionalProperties'), 'a read follows the read rules, so a value field not declared guaranteed admits null');
        self::assertSame(['type' => 'integer'], self::at($document, 'components', 'schemas', 'TrItemsCreateResourceMeta', 'additionalProperties'));
        self::assertNull(self::at($document, 'components', 'schemas', 'TrPlainsResourceMeta'));

        self::assertSame(['$ref' => '#/components/schemas/TrNotesItemsAddRelationshipDocument'], self::at($document, 'paths', '/tr-notes/{id}/relationships/items', 'post', 'responses', '200', 'content', 'application/vnd.api+json', 'schema'));
        self::assertSame(['$ref' => '#/components/schemas/TrExportsCreateAcceptedDocument'], self::at($document, 'paths', '/tr-exports', 'post', 'responses', '202', 'content', 'application/vnd.api+json', 'schema'));
        self::assertSame(['$ref' => '#/components/schemas/TrNotesQueueActionAcceptedDocument'], self::at($document, 'paths', '/tr-notes/{id}/-actions/queue', 'post', 'responses', '202', 'content', 'application/vnd.api+json', 'schema'));

        self::assertTrue(self::at($document, 'components', 'schemas', 'TrNotesAtomicAddOperation', 'x-request-meta'));
        self::assertTrue(self::at($document, 'components', 'schemas', 'TrNotesItemsAtomicRelationshipAddOperation', 'x-request-meta'));
        self::assertSame(['idempotencyKey', 'batchTag'], self::at($document, 'components', 'schemas', 'TrNotesAtomicAddOperation', 'properties', 'meta', 'required'));
    }

    abstract protected function bootInDebug(): void;

    /**
     * The stored `source` of a note, read back through the data layer.
     */
    abstract protected function sourceOf(string $id): ?string;

    /**
     * @param array<string, mixed> $batch
     */
    private function atomic(array $batch): Response
    {
        return $this->handleRaw('/operations', \json_encode($batch, \JSON_THROW_ON_ERROR), self::ATOMIC, extraServer: ['HTTP_ACCEPT' => self::ATOMIC]);
    }

    /**
     * Each `422` error's pointer and detail.
     *
     * @return array<string, string>
     */
    private function violations(Response $response): array
    {
        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        $violations = [];
        foreach ($errors as $error) {
            self::assertIsArray($error);
            $pointer = self::at($error, 'source', 'pointer');
            self::assertIsString($pointer);
            $violations[$pointer] = \is_string($error['detail'] ?? null) ? $error['detail'] : '';
        }

        return $violations;
    }

    /**
     * @param array<array-key, mixed> $document
     */
    private static function at(array $document, int|string ...$keys): mixed
    {
        $node = $document;
        foreach ($keys as $key) {
            if (!\is_array($node) || !\array_key_exists($key, $node)) {
                return null;
            }
            $node = $node[$key];
        }

        return $node;
    }
}
