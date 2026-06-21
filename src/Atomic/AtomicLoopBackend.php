<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Atomic;

use haddowg\JsonApi\Atomic\AtomicLoopBackendInterface;
use haddowg\JsonApi\Atomic\AtomicOperationCode;
use haddowg\JsonApi\Atomic\AtomicResult;
use haddowg\JsonApi\Atomic\LocalIdRegistry;
use haddowg\JsonApi\Atomic\OperationDescriptor;
use haddowg\JsonApi\Atomic\Ref;
use haddowg\JsonApi\Operation\AddToRelationshipOperation;
use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\DeleteResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Operation\RemoveFromRelationshipOperation;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Operation\UpdateRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateResourceOperation;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiBundle\DataPersister\TransactionalDataPersisterInterface;
use haddowg\JsonApiBundle\DataPersister\WriteTransactionContext;
use haddowg\JsonApiBundle\Operation\CrudOperationHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RouterInterface;

/**
 * The bundle's Atomic Operations executor — the storage- and Symfony-specific
 * {@see AtomicLoopBackendInterface} the framework-agnostic {@see \haddowg\JsonApi\Atomic\AtomicLoop}
 * drives once per `POST /operations` batch (Slice C).
 *
 * It turns each parsed {@see OperationDescriptor} into the matching core CRUD
 * operation VO and dispatches it through the bundle's own
 * {@see CrudOperationHandler} **in-process** — calling `handle()` directly, NOT
 * `Server::dispatch()`, so serving fires once for the whole batch (not per
 * sub-operation) and the per-op After* lifecycle hooks defer to post-commit via the
 * shared {@see WriteTransactionContext}. The whole batch runs inside one transaction
 * opened on every participating persister, committed together on success or rolled
 * back together on the first failure.
 *
 * **Decoration is batch-scoped.** A handler decorator therefore wraps the batch as a
 * whole (it sees the single {@see \haddowg\JsonApi\Operation\AtomicOperationsOperation}),
 * NOT each sub-operation: the sub-operations re-enter the same handler instance this
 * backend was constructed with, bypassing any decorator chain. Per-sub-op decoration
 * is intentionally out of scope.
 *
 * **Local-id rewrite + registration.**
 *  - REWRITE (before dispatch): every `{type, lid}` the operation references — in its
 *    `ref` and in any linkage inside its `data` — is rewritten to `{type, id}` by
 *    resolving the `lid` through the shared {@see LocalIdRegistry}. A miss throws
 *    {@see \haddowg\JsonApi\Exception\LocalIdNotFound}, which the loop pointer-prefixes
 *    with the operation index and renders as the rolled-back error.
 *  - REGISTER (after an add): an `add` of a resource whose `data` carried a `lid`
 *    registers `(type, lid) → assigned-id` after the sub-operation creates the
 *    resource, so a later operation can reference it. A duplicate `(type, lid)` throws
 *    {@see \haddowg\JsonApi\Exception\LocalIdConflict}.
 *
 * **Pre-flight.** Before opening anything ({@see begin()}), every participating type's
 * persister is resolved and must implement {@see TransactionalDataPersisterInterface};
 * if any does not, the batch is refused with {@see AtomicOperationsNotSupported}
 * (`4xx`) before a single write — so a partial, non-rolled-back batch can never occur.
 *
 * **Result rendering.** A sub-operation's {@see DataResponse} is rendered to its
 * `{data?, meta?}` fragment (the result-object members the extension allows — never
 * `links`/`included`); a {@see NoContentResponse} (a delete, or a relationship/whole
 * update with nothing to return) is the empty result object.
 */
final class AtomicLoopBackend implements AtomicLoopBackendInterface
{
    /**
     * The participating transactional persisters, keyed by their object hash so the
     * same persister (e.g. the one Doctrine fallback shared across every entity type)
     * is begun / committed / rolled back exactly once.
     *
     * @var array<string, TransactionalDataPersisterInterface>
     */
    private array $transactional;

    /**
     * Whether {@see begin()} has actually opened the transactions, so a guarded
     * {@see rollback()} (e.g. a commit-failure rollback, or an executeOne throw before
     * begin completed) never double-rolls-back or rolls back a never-opened batch.
     */
    private bool $opened = false;

    private readonly LoggerInterface $logger;

    /**
     * @param list<OperationDescriptor> $descriptors the parsed batch, in request order
     * @param Server                    $server      the resolved server the batch dispatched on
     * @param JsonApiRequestInterface   $request     the batch request, the synthetic per-op requests are derived from
     * @param ?LoggerInterface          $logger      logs a post-commit hook that throws after the batch committed (best-effort; defaults to {@see NullLogger})
     */
    public function __construct(
        private readonly array $descriptors,
        private readonly Server $server,
        private readonly JsonApiRequestInterface $request,
        private readonly CrudOperationHandler $handler,
        private readonly DataPersisterRegistry $persisters,
        private readonly WriteTransactionContext $context,
        private readonly RouterInterface $router,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();

        // Pre-flight: resolve every participating type's persister up front and refuse
        // the batch if ANY is not transactional — before begin() opens anything.
        $this->transactional = $this->collectTransactionalPersisters();
    }

    public function begin(): void
    {
        // Open EVERY participating transaction FIRST — before activating the
        // deferred-hook context. Core's AtomicLoop::run() calls begin() OUTSIDE its
        // try/catch, so a persister's beginTransaction() that throws here would
        // otherwise leave the context active and an earlier transaction open, relying
        // solely on kernel.reset to clean up. Instead, if any begin throws, roll back
        // the ones already opened and rethrow — so a begin failure leaves nothing open
        // and the context inactive (defense-in-depth alongside the kernel.reset).
        $opened = [];
        try {
            foreach ($this->transactional as $hash => $persister) {
                $persister->beginTransaction();
                $opened[$hash] = $persister;
            }
        } catch (\Throwable $throwable) {
            foreach ($opened as $persister) {
                // A persister's own rollback() is a safe no-op when already closed.
                $persister->rollback();
            }

            throw $throwable;
        }

        // Every transaction is open: activate the context so each sub-operation's
        // After* dispatch is enqueued (not fired) for the life of the batch, and mark
        // the batch opened so a later rollback() unwinds it.
        $this->context->activate();
        $this->opened = true;
    }

    public function executeOne(OperationDescriptor $op, LocalIdRegistry $lids): AtomicResult
    {
        // (a) rewrite the `{type, lid}` the operation's `ref` references to `{type, id}`
        // via the shared registry — a miss throws LocalIdNotFound, which the loop
        // pointer-prefixes with this operation's index. (The `data` lids are rewritten in
        // (b'), once the target's relationship-ness is known.)
        $ref = $op->ref !== null ? $this->resolveRefLid($op->ref, $lids) : null;

        // (b) resolve the target — from ref (type[/id][/relationship]), by matching the
        // href against the router, or — for a resource `add` carrying neither — from the
        // resource object's own `data.type` (the create's only target source). The
        // `data.type` of a create is never a lid, so resolving the target from the raw
        // `data` (before its lids are rewritten) is safe.
        $target = match (true) {
            $ref !== null => $this->targetFromRef($ref),
            $op->href !== null => $this->targetFromHref($op->href),
            default => new Target($this->createTypeFromData($op->data)),
        };

        // (b') rewrite every `{type, lid}` the operation's `data` references. WHICH lids
        // are references depends on the target: a relationship endpoint's `data` IS
        // linkage (a bare identifier, or a list of them), so its own `{type, lid}` is a
        // reference to resolve; a resource endpoint's `data` is a resource object, so only
        // the linkage inside its `relationships[*]` is a reference — the resource object's
        // OWN top-level `lid` (the create's local id) is NEVER resolved here (it is
        // registered after the add). This distinction cannot be made from `data`'s shape
        // alone — an attribute-less resource object `{type, lid}` is indistinguishable from
        // a bare identifier — so the target drives it.
        $data = $this->resolveDataLids($op->data, $target->hasRelationship(), $lids);

        // (c)/(d) build the matching core CRUD operation VO off a synthetic per-op
        // request carrying `{data: …}`, and dispatch it through the handler's own per-op
        // CRUD arms IN-PROCESS (handle(), never Server::dispatch — serving already fired
        // once for the batch).
        $operation = $this->buildOperation($op->opCode, $target, $data);
        $response = $this->handler->handle($operation);

        // A sub-operation that resolved to a 404/400 (a missing update/delete target, an
        // unknown relationship) returns an ErrorResponse rather than throwing; surface it
        // as the throw the loop expects so the whole batch rolls back at this index.
        if ($response instanceof \haddowg\JsonApi\Response\ErrorResponse) {
            throw new SubOperationFailed($response, $this->server, $this->request);
        }

        // (f) a NoContentResponse (a delete, or an update with nothing to return) is the
        // empty result object; everything else renders to its `{data?, meta?}` fragment ONCE
        // (reused for both the lid registration below and the result).
        if ($response instanceof NoContentResponse) {
            return AtomicResult::empty();
        }
        $fragment = $this->fragmentOf($response);

        // (e) after an add that created a resource, register (type, lid) → assigned id so
        // a later operation can reference it (a duplicate (type, lid) throws LocalIdConflict).
        if ($op->opCode === AtomicOperationCode::Add) {
            $this->registerCreatedLid($target->type, $data, $fragment, $lids);
        }

        return AtomicResult::fromDocument($fragment);
    }

    public function commit(): void
    {
        // Commit each participating persister in turn. With a SINGLE persister (the default
        // — one shared Doctrine EntityManager, or the in-memory store) this is genuinely
        // atomic. With MULTIPLE distinct persisters there is no two-phase commit across
        // them, so a later commit can fail after an earlier one has already made its writes
        // durable. We cannot undo a committed persister, but we MUST still roll back the
        // ones that have NOT yet committed — otherwise their open transactions would leak —
        // and re-raise the failure so the loop renders the batch as failed. The cross-store
        // in-memory witness is unaffected: its commit() (discardSnapshot) cannot fail, so
        // its multi-store batches stay all-or-nothing.
        $committed = [];
        try {
            foreach ($this->transactional as $hash => $persister) {
                $persister->commit();
                $committed[$hash] = true;
            }
        } catch (\Throwable $throwable) {
            foreach ($this->transactional as $hash => $persister) {
                if (!isset($committed[$hash])) {
                    // A persister's own rollback() is a safe no-op when already closed.
                    $persister->rollback();
                }
            }

            throw $throwable;
        }

        // The data is durable: drain the deferred After* hooks (FIFO). The batch has
        // ALREADY committed, so a hook that throws must NOT turn a successful, durably
        // committed batch into a failure (and there is nothing to roll back — the data
        // stands). Each hook exception is therefore caught + LOGGED and the remaining
        // hooks still run; the batch's response is unaffected. The context is ALWAYS
        // deactivated, even if a hook threw, so it never stays active for the next
        // request (a finally belt alongside kernel.reset).
        try {
            $this->context->drain(function (\Throwable $throwable): void {
                $this->logger->error(
                    'A deferred After* lifecycle hook threw after an Atomic Operations batch committed; '
                    . 'the batch already succeeded, so the error is logged and ignored.',
                    ['exception' => $throwable],
                );
            });
        } finally {
            $this->context->deactivate();
        }
    }

    public function rollback(): void
    {
        // Guarded: roll back only the transactions begin() actually opened (a pre-begin
        // executeOne failure, or a never-opened batch, leaves nothing to undo), and each
        // persister's own rollback() is itself a safe no-op when already closed / inactive
        // (the Slice B carry-forward), so a commit-failure rollback never throws a secondary
        // error that masks the original.
        if ($this->opened) {
            foreach ($this->transactional as $persister) {
                $persister->rollback();
            }
            $this->opened = false;
        }

        // ALWAYS discard the deferred queue and deactivate, even when nothing was opened —
        // a rolled-back batch fires no After* hooks, and the context must never stay active.
        $this->context->deactivate();
    }

    /**
     * Resolves the persister of every participating type and returns the transactional
     * ones, keyed by object hash (so one shared persister begins/commits/rolls back
     * once). The pre-flight scan refuses the whole batch up front when:
     *  - a participating type has no registered persister — a client error (an unknown
     *    type, {@see AtomicTargetTypeUnknown}, `404`), mirroring the routing miss a
     *    direct CRUD call would hit (there is no routing step inside a batch);
     *  - a participating type's persister is NOT a
     *    {@see TransactionalDataPersisterInterface} ({@see AtomicOperationsNotSupported}).
     *
     * Multiple DISTINCT transactional persisters are tolerated (a batch may span types
     * backed by separate stores), but the all-or-nothing guarantee then rests on
     * {@see commit()} being able to undo the not-yet-committed persisters when a later
     * commit fails — see {@see commit()} for the cross-persister-commit boundary.
     *
     * @return array<string, TransactionalDataPersisterInterface>
     *
     * @throws AtomicTargetTypeUnknown
     * @throws AtomicOperationsNotSupported
     */
    private function collectTransactionalPersisters(): array
    {
        $transactional = [];
        foreach ($this->participatingTypes() as $type) {
            if (!$this->persisters->supportsType($type)) {
                throw new AtomicTargetTypeUnknown($type);
            }

            $persister = $this->persisters->forType($type);
            if (!$persister instanceof TransactionalDataPersisterInterface) {
                throw new AtomicOperationsNotSupported($type);
            }

            $transactional[\spl_object_hash($persister)] = $persister;
        }

        return $transactional;
    }

    /**
     * The distinct primary types the batch writes to, resolved from each operation's
     * target (a `ref` carries its `type` directly; an `href` is matched against the
     * router to read its `_jsonapi_type` route default). A `ref` carrying only a `lid`
     * still names its `type`, so the type is known pre-flight without resolving the lid.
     *
     * @return list<string>
     */
    private function participatingTypes(): array
    {
        $types = [];
        foreach ($this->descriptors as $descriptor) {
            $type = match (true) {
                $descriptor->ref !== null => $descriptor->ref->type,
                $descriptor->href !== null => $this->typeFromHref($descriptor->href),
                // A resource `add` carrying neither ref nor href: its target is its
                // own `data.type` (the parser guarantees only an `add` may omit both).
                default => $this->createTypeFromData($descriptor->data),
            };

            $types[$type] = true;
        }

        return \array_keys($types);
    }

    /**
     * The `data.type` of a resource `add` that targets its endpoint neither by `ref`
     * nor `href` — the create's only target source. An add whose `data` carries no
     * string `type` is malformed; that is the parser's job, but a defensive
     * {@see AtomicHrefUnresolvable}-style guard keeps the executor total.
     */
    private function createTypeFromData(mixed $data): string
    {
        if (\is_array($data) && isset($data['type']) && \is_string($data['type']) && $data['type'] !== '') {
            return $data['type'];
        }

        throw new \haddowg\JsonApi\Exception\AtomicOperationsInvalid(
            "An atomic 'add' with no 'ref'/'href' must carry a resource object with a 'type'.",
            '',
        );
    }

    /**
     * Builds the core CRUD operation VO for the descriptor's code and resolved target,
     * carrying a synthetic per-operation {@see JsonApiRequestInterface} whose parsed
     * body is `{data: <data>}` (lids already rewritten). The relationship-vs-resource
     * distinction rides the target's relationship name:
     *  - `add`    → {@see CreateResourceOperation} | {@see AddToRelationshipOperation};
     *  - `update` → {@see UpdateResourceOperation}  | {@see UpdateRelationshipOperation};
     *  - `remove` → {@see DeleteResourceOperation}  | {@see RemoveFromRelationshipOperation}.
     */
    private function buildOperation(AtomicOperationCode $opCode, Target $target, mixed $data): JsonApiOperationInterface
    {
        $query = new QueryParameters([], [], [], [], []);

        if ($target->hasRelationship()) {
            // A relationship operation's verb: replace = PATCH, add = POST, remove = DELETE.
            $method = match ($opCode) {
                AtomicOperationCode::Add => 'POST',
                AtomicOperationCode::Update => 'PATCH',
                AtomicOperationCode::Remove => 'DELETE',
            };
            $body = $this->subRequest($target, $method, $data);
            $context = new OperationContext($this->server, $body);

            return match ($opCode) {
                AtomicOperationCode::Add => new AddToRelationshipOperation($target, $query, $context, $body),
                AtomicOperationCode::Update => new UpdateRelationshipOperation($target, $query, $context, $body),
                AtomicOperationCode::Remove => new RemoveFromRelationshipOperation($target, $query, $context, $body),
            };
        }

        // A resource operation's verb: add = POST (create), update = PATCH, remove = DELETE.
        // The verb is load-bearing — core's create path rejects a client-supplied `data.id`
        // (CLIENT_GENERATED_ID_NOT_SUPPORTED), so an update MUST be PATCH so the id is read
        // as the target, not a client-generated create id.
        $method = match ($opCode) {
            AtomicOperationCode::Add => 'POST',
            AtomicOperationCode::Update => 'PATCH',
            AtomicOperationCode::Remove => 'DELETE',
        };
        $body = $this->subRequest($target, $method, $data);
        $context = new OperationContext($this->server, $body);

        return match ($opCode) {
            AtomicOperationCode::Add => new CreateResourceOperation($target, $query, $context, $body),
            AtomicOperationCode::Update => new UpdateResourceOperation($target, $query, $context, $body),
            AtomicOperationCode::Remove => new DeleteResourceOperation($target, $query, $context),
        };
    }

    /**
     * A synthetic per-operation request derived from the batch request: the same
     * headers/attributes (so `_jsonapi_server` resolves and the handler's request-aware
     * predicates see the same caller) with the method + URI rewritten to the
     * sub-operation's verb/path and a parsed body of `{data: <data>}`. The verb is
     * load-bearing for the create-vs-update client-id rule (an update is PATCH so its
     * `data.id` is the target, not a rejected client-generated create id).
     */
    private function subRequest(Target $target, string $method, mixed $data): JsonApiRequestInterface
    {
        $uri = $this->request->getUri()->withPath($this->pathFor($target));

        $request = $this->request
            ->withMethod($method)
            ->withUri($uri, preserveHost: true)
            ->withParsedBody(['data' => $data]);
        \assert($request instanceof JsonApiRequestInterface);

        return $request;
    }

    /**
     * The URL path a sub-operation addresses (its self link / route), so the synthetic
     * request's URI matches the equivalent direct call. Built from the resolved target.
     */
    private function pathFor(Target $target): string
    {
        $path = '/' . $target->type;
        if ($target->id !== null) {
            $path .= '/' . $target->id;
        }
        if ($target->relationship !== null) {
            $path .= ($target->isRelationshipEndpoint ? '/relationships/' : '/') . $target->relationship;
        }

        return $path;
    }

    /**
     * Builds the {@see Target} from a (lid-resolved) {@see Ref}: a relationship ref
     * targets the relationship-linkage endpoint (the atomic relationship operations are
     * the linkage `add`/`update`/`remove`, never the related-resource read).
     */
    private function targetFromRef(Ref $ref): Target
    {
        return new Target(
            $ref->type,
            $ref->id,
            $ref->relationship,
            isRelationshipEndpoint: $ref->relationship !== null,
        );
    }

    /**
     * Builds the {@see Target} from an `href` by matching it against the router: the
     * matched route's `_jsonapi_*` defaults give the type, id, relationship name, and
     * whether it is the relationship-linkage endpoint — exactly what the
     * {@see \haddowg\JsonApiBundle\Operation\TargetResolver} reads on a direct call. An
     * href matching no JSON:API route is an unprocessable target.
     */
    private function targetFromHref(string $href): Target
    {
        $match = $this->matchHref($href);

        $type = $match['_jsonapi_type'] ?? null;
        if (!\is_string($type) || $type === '') {
            throw new AtomicHrefUnresolvable($href);
        }

        $id = $match['id'] ?? null;
        $relationship = $match['relationship'] ?? null;

        return new Target(
            $type,
            \is_string($id) ? $id : null,
            \is_string($relationship) && $relationship !== '' ? $relationship : null,
            isRelationshipEndpoint: ($match['_jsonapi_relationship_endpoint'] ?? null) === true,
        );
    }

    /**
     * The primary `_jsonapi_type` an `href` resolves to, for the pre-flight persister
     * scan (no id/relationship needed there).
     */
    private function typeFromHref(string $href): string
    {
        $type = $this->matchHref($href)['_jsonapi_type'] ?? null;
        if (!\is_string($type) || $type === '') {
            throw new AtomicHrefUnresolvable($href);
        }

        return $type;
    }

    /**
     * Matches an `href` (its path) against the router and returns the matched route
     * defaults, or throws {@see AtomicHrefUnresolvable} when it matches no route — so a
     * bogus href fails the batch with a clear `4xx` rather than a router exception.
     *
     * The match is **method-agnostic**: the executor only needs the route's defaults
     * (type / id / relationship), not its verb (the operation's `op` carries the verb).
     * The router's request context during a `POST /operations` request has method POST,
     * so matching a `GET`/`PATCH`/`DELETE`-only route path would otherwise throw a
     * {@see MethodNotAllowedException}; the context method is neutralised for the match
     * and restored after, so the defaults resolve regardless of the route's verbs.
     *
     * @return array<string, mixed>
     */
    private function matchHref(string $href): array
    {
        $path = (string) \parse_url($href, \PHP_URL_PATH);
        if ($path === '') {
            $path = $href;
        }

        $context = $this->router->getContext();
        $method = $context->getMethod();

        try {
            // Match against every verb of the path's routes; we want the defaults, not
            // verb validation. GET is set because every addressable JSON:API path has a
            // GET route (a resource/collection/related/relationship read), so the match
            // resolves the defaults without a MethodNotAllowed.
            $context->setMethod('GET');

            return $this->router->match($path);
        } catch (ResourceNotFoundException | MethodNotAllowedException) {
            throw new AtomicHrefUnresolvable($href);
        } finally {
            $context->setMethod($method);
        }
    }

    /**
     * Resolves a {@see Ref}'s `lid` to a server `id` via the registry (a `ref` with an
     * `id` already, or with no `lid`, is returned unchanged). A miss throws
     * {@see \haddowg\JsonApi\Exception\LocalIdNotFound}.
     */
    private function resolveRefLid(Ref $ref, LocalIdRegistry $lids): Ref
    {
        if ($ref->lid === null) {
            return $ref;
        }

        return new Ref($ref->type, $lids->resolve($ref->type, $ref->lid), null, $ref->relationship);
    }

    /**
     * Rewrites every `{type, lid}` resource-identifier in the operation's `data` to
     * `{type, id}` via the registry, leaving the rest verbatim. WHICH lids count as
     * references is driven by the target, not by `data`'s shape — the shape alone cannot
     * tell an attribute-less resource object `{type, lid}` from a bare to-one identifier
     * `{type, lid}`. So `$targetsRelationship` (true when the operation addresses a
     * relationship endpoint) disambiguates:
     *  - a RELATIONSHIP endpoint's `data` IS linkage — a single resource-identifier (a
     *    to-one `update`), a list of them (a to-many operation), or `null` (clearing a
     *    to-one): each identifier's own `{type, lid}` is a reference to resolve;
     *  - a RESOURCE endpoint's `data` is a resource object — only the linkage inside its
     *    `relationships[*].data` is a reference; the resource object's OWN top-level `lid`
     *    (the create's local id) is NEVER resolved here (it is registered after the add),
     *    so an attribute-less `add` carrying just `{type, lid}` is left intact.
     *  - `null` (a resource `remove`, or a cleared to-one) is returned unchanged.
     */
    private function resolveDataLids(mixed $data, bool $targetsRelationship, LocalIdRegistry $lids): mixed
    {
        if (!\is_array($data)) {
            return $data;
        }

        // A relationship endpoint's `data` is linkage: a list of identifiers (to-many) or
        // a single identifier (to-one) — each identifier's own lid is a reference.
        if ($targetsRelationship) {
            if (\array_is_list($data)) {
                return \array_map(fn(mixed $item): mixed => $this->resolveIdentifierLid($item, $lids), $data);
            }

            return $this->resolveIdentifierLid($data, $lids);
        }

        // A resource endpoint's `data` is a resource object: only the linkage inside its
        // `relationships[*]` is a reference; the object's own top-level lid is left in place.
        if (\array_key_exists('relationships', $data) && \is_array($data['relationships'])) {
            $data['relationships'] = $this->resolveRelationshipsLids($data['relationships'], $lids);
        }

        return $data;
    }

    /**
     * Resolves the lids in each named relationship's linkage of a resource object's
     * `relationships` member (a to-one `data` identifier, or a to-many `data` list of
     * identifiers).
     *
     * @param array<array-key, mixed> $relationships
     *
     * @return array<array-key, mixed>
     */
    private function resolveRelationshipsLids(array $relationships, LocalIdRegistry $lids): array
    {
        foreach ($relationships as $name => $relationship) {
            if (!\is_array($relationship) || !\array_key_exists('data', $relationship)) {
                continue;
            }

            $linkage = $relationship['data'];
            if (\is_array($linkage) && \array_is_list($linkage)) {
                $relationship['data'] = \array_map(fn(mixed $item): mixed => $this->resolveIdentifierLid($item, $lids), $linkage);
            } elseif (\is_array($linkage)) {
                $relationship['data'] = $this->resolveIdentifierLid($linkage, $lids);
            }

            $relationships[$name] = $relationship;
        }

        return $relationships;
    }

    /**
     * Rewrites one resource-identifier carrying a `lid` to one carrying the resolved
     * `id` (dropping the `lid`, preserving `meta`), leaving an identifier that already
     * names an `id` — or any non-identifier value — untouched. A miss throws
     * {@see \haddowg\JsonApi\Exception\LocalIdNotFound}.
     */
    private function resolveIdentifierLid(mixed $identifier, LocalIdRegistry $lids): mixed
    {
        if (!\is_array($identifier) || !isset($identifier['lid']) || !\is_string($identifier['lid'])) {
            return $identifier;
        }

        $type = $identifier['type'] ?? null;
        if (!\is_string($type)) {
            return $identifier;
        }

        $identifier['id'] = $lids->resolve($type, $identifier['lid']);
        unset($identifier['lid']);

        return $identifier;
    }

    /**
     * Registers `(type, lid) → assigned id` after an `add` created a resource whose
     * `data` carried a `lid`, reading the assigned id off the already-rendered result
     * fragment. A duplicate `(type, lid)` throws
     * {@see \haddowg\JsonApi\Exception\LocalIdConflict}.
     *
     * @param array<string, mixed> $fragment the rendered `{data?, meta?}` result fragment
     */
    private function registerCreatedLid(string $type, mixed $data, array $fragment, LocalIdRegistry $lids): void
    {
        if (!\is_array($data) || !isset($data['lid']) || !\is_string($data['lid'])) {
            return;
        }

        $resource = $fragment['data'] ?? null;
        $id = \is_array($resource) ? ($resource['id'] ?? null) : null;
        if (!\is_string($id)) {
            return;
        }

        $lids->register($type, $data['lid'], $id);
    }

    /**
     * Renders a response value object to its `{data?, meta?}` result-object fragment —
     * the only members the extension allows in a result object. The response is rendered
     * through core's own render seam ({@see \haddowg\JsonApi\Response\AbstractResponse::toPsrResponse()}),
     * then decoded, and only `data`/`meta` are kept (never `links`/`included`/`jsonapi`).
     *
     * @return array<string, mixed>
     */
    private function fragmentOf(object $response): array
    {
        \assert($response instanceof \haddowg\JsonApi\Response\AbstractResponse);

        $psr = $response->toPsrResponse($this->server, $this->request);
        $body = (string) $psr->getBody();
        if ($body === '') {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        $fragment = [];
        if (\array_key_exists('data', $decoded)) {
            $fragment['data'] = $decoded['data'];
        }
        if (\array_key_exists('meta', $decoded)) {
            $fragment['meta'] = $decoded['meta'];
        }

        return $fragment;
    }
}
