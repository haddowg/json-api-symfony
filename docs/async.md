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

## Reporting completion — `303 See Other`

Model the job-status endpoint as a [custom action](actions.md) that returns
`$context->seeOther($url)` once the queued work has produced the resource:

```php
use haddowg\JsonApi\Response\SeeOtherResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

#[AsJsonApiAction(type: 'jobs', path: 'result', methods: ['GET'])]
final class JobResult implements ActionHandlerInterface
{
    public function handle(ActionContext $context): SeeOtherResponse
    {
        $job = $context->entity();
        \assert($job instanceof Job);

        // Still running → 202 again; done → redirect to the produced resource.
        return $context->seeOther('https://example.test/articles/' . $job->createdId);
    }
}
```

```http
HTTP/1.1 303 See Other
Location: https://example.test/articles/42
```

While the job is still running the same action can return
`$context->accepted($pollUrl)->withRetryAfter(30)` to answer `202` again, so a polling
client sees `202` until the work finishes and then a single `303`.

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
