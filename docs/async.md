# Asynchronous writes (`202 Accepted` / `303 See Other`)

JSON:API 1.1 describes an [asynchronous-processing](https://jsonapi.org/recommendations/#asynchronous-processing)
lifecycle: a server that cannot finish a write within the request accepts it with a
`202 Accepted`, points the client at a **job resource** to poll (a `Content-Location`
header, optionally a `Retry-After` hint), and — once the work completes — answers a
`GET` on that job resource with `303 See Other`, redirecting to the resource the
operation produced.

The bundle exposes this as a **thin seam**: your persister decides to go async and
returns a marker; the handler renders the spec-correct `202`. *How* you queue the work
is your choice — the recipe below uses [Symfony Messenger](https://symfony.com/doc/current/messenger.html),
but nothing about Messenger is baked in.

Every part of this lifecycle is **reflected in the generated OpenAPI document** via the
per-operation [response declarations](resources.md#per-operation-response-declarations):
the write advertises the `202`, and the job type's fetch advertises the `303`. The
`examples/music-catalog-symfony` app wires this as the `catalog-exports` (always-async
create) / `export-jobs` (fetch-one `303` completion) pair, and the Laravel package
projects a byte-identical document for it.

## Accepting the write — `AcceptedForProcessing`

A [`DataPersister`](data-layer.md) that dispatches a write instead of committing it
returns an `AcceptedForProcessing` from `create()` (or `update()`) in place of the
persisted entity. The [`CrudOperationHandler`](lifecycle.md) renders it as a `202`
rather than the usual `201`/`200`:

```php
use haddowg\JsonApiBundle\DataPersister\AcceptedForProcessing;
use haddowg\JsonApiBundle\DataPersister\DataPersisterInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class AsyncArticlePersister implements DataPersisterInterface
{
    public function __construct(private MessageBusInterface $bus) {}

    public function supports(string $type): bool
    {
        return $type === 'articles';
    }

    public function instantiate(string $type): object
    {
        return new Article();
    }

    public function create(string $type, object $entity): object
    {
        // Hand the work off to the queue instead of persisting inline.
        $jobId = $this->bus->dispatch(new CreateArticle($entity))
            ->last(\Symfony\Component\Messenger\Stamp\HandledStamp::class)
            ?->getResult();

        // Point the client at a job resource it can poll for completion.
        return AcceptedForProcessing::poll('https://example.test/jobs/' . $jobId)
            ->withJob(new Job($jobId, 'queued'), 'jobs')
            ->withRetryAfter(30);
    }

    // update()/delete()/mutateRelationship() as usual…
}
```

The response is a `202`:

```http
HTTP/1.1 202 Accepted
Content-Type: application/vnd.api+json
Content-Location: https://example.test/jobs/9f3b
Retry-After: 30

{ "data": { "type": "jobs", "id": "9f3b", "attributes": { "status": "queued" } } }
```

- `AcceptedForProcessing::poll($url)` sets the `Content-Location` — the URL the client
  polls.
- `->withJob($job, 'jobs')` renders `$job` as the `202` body through the `jobs` type's
  registered serializer. Omit it (or use `->withMeta([...])`) for a meta-only status
  document.
- `->withRetryAfter(30)` sets `Retry-After` in delta-seconds; a `\DateTimeInterface`
  is emitted as an HTTP-date instead.

The `jobs` type is an ordinary JSON:API type — register a serializer for it (a
standalone `#[AsJsonApiSerializer(type: 'jobs')]`, or a full resource if you want its
own endpoints). Persist the job wherever your queue tracks state.

Declare the `202` on the resource so the document advertises it — a *maybe-async* write
lists both the sync and the async status:

```php
use haddowg\JsonApi\OpenApi\Metadata\{Accepted, Created};

#[AsJsonApiResource(create: [new Created(), new Accepted('jobs')])]
final class ArticleResource extends AbstractResource { /* … */ }
```

An *always*-async type declares `create: [new Accepted('jobs')]` (a `202` only). See
[per-operation response declarations](resources.md#per-operation-response-declarations).

## Reporting completion — `303 See Other`

The spec models completion as a `GET` on the job's own URL: `200` (the job resource)
while the work runs, then `303 See Other` to the produced resource once it is done.
The bundle drives that from the **`jobs` type's fetch-one** — implement
`haddowg\JsonApi\Resource\ResolvesCompletionRedirect` on the job resource (or its
serializer) and declare `fetchOne: [new Ok(), new SeeOther()]` so the `303` is in the
document:

```php
use haddowg\JsonApi\OpenApi\Metadata\{Ok, SeeOther};
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\ResolvesCompletionRedirect;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true, fetchOne: [new Ok(), new SeeOther()])]
final class JobResource extends AbstractResource implements ResolvesCompletionRedirect
{
    // Done → the produced resource's URL (the fetch renders 303); still running → null (200).
    public function completionLocation(object $entity): ?string
    {
        \assert($entity instanceof Job);

        return $entity->isDone() ? 'https://example.test/articles/' . $entity->createdId : null;
    }

    public function fields(): array { /* id + status … */ }
}
```

```http
GET /jobs/9f3b            → 200   (still running — the job resource)
GET /jobs/9f3b            → 303   Location: https://example.test/articles/42   (done)
```

**Or via a custom action.** When completion isn't a plain job fetch — a side-effecting
`POST`, a distinct result endpoint — model it as a [custom action](actions.md)
declaring `responds: [new Accepted('jobs'), new SeeOther()]`, so the document
advertises both the still-running `202` and the completion `303`:

```php
use haddowg\JsonApi\OpenApi\Metadata\{Accepted, SeeOther};
use haddowg\JsonApi\Response\AcceptedResponse;
use haddowg\JsonApi\Response\SeeOtherResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

#[AsJsonApiAction(type: 'jobs', path: 'result', methods: ['GET'], responds: [new Accepted('jobs'), new SeeOther()])]
final class JobResult implements ActionHandlerInterface
{
    public function handle(ActionContext $context): AcceptedResponse|SeeOtherResponse
    {
        $job = $context->entity();
        \assert($job instanceof Job);

        // Still running → 202 again; done → redirect to the produced resource.
        return $job->isDone()
            ? $context->seeOther('https://example.test/articles/' . $job->createdId)
            : $context->accepted('https://example.test/jobs/' . $job->id)->withRetryAfter(30);
    }
}
```

so a polling client sees `202` until the work finishes and then a single `303`.

## Notes

- **Atomic Operations.** An async accept cannot join an
  [Atomic Operations](atomic-operations.md) batch — it defers the write past the
  batch's all-or-nothing commit — so a persister that returns `AcceptedForProcessing`
  inside a batch fails that sub-operation (`422`) and the batch rolls back. Keep async
  types out of atomic batches.
- **Scope.** The seam covers `create()` and `update()`. `delete()` returns `void`, so
  an async delete is not expressible through it today.
- **Portability.** `AcceptedResponse`/`SeeOtherResponse` are core, framework-neutral
  response value objects, so the Laravel package produces byte-identical `202`/`303`
  responses over its own queue.
