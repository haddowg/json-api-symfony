<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\MetaContext;
use haddowg\JsonApi\Resource\RequestMetaContext;
use haddowg\JsonApiBundle\Hook\HookContext;
use haddowg\JsonApiBundle\Hook\ResourceLifecycleHooksInterface;
use haddowg\JsonApiBundle\Hook\ResourceLifecycleHooksTrait;

/**
 * Notes declare the meta each write takes, their resource objects' meta both ways, the
 * meta-only document adding items answers with, and the one replacing the lead alone
 * answers with. Inside an atomic batch a write's
 * `data.meta` names its source `batchSource` rather than `source`.
 */
abstract class TrNoteResource extends AbstractResource implements ResourceLifecycleHooksInterface
{
    use ResourceLifecycleHooksTrait;

    public static string $type = 'tr-notes';

    /**
     * The reason the last delete's beforeDelete() hook read off the typed meta.
     */
    public static mixed $deletedBecause = null;
    public function getType(mixed $object): string
    {
        return $object instanceof TrNote ? static::$type : '';
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('createdWith')->readOnly()->nullable(),
            BelongsToMany::make('items', 'tr-items'),
            BelongsTo::make('lead', 'tr-items')->nullable()->identifierMeta(
                static fn(TrNote $note, mixed $item, JsonApiRequestInterface $request): array => $request->hasHeader('X-Bogus-Meta') ? ['role' => 'owner', 'bogus' => true] : ['role' => 'owner'],
                [Str::make('role')],
            ),
        ];
    }

    public function requestDocumentMeta(RequestMetaContext $context): iterable
    {
        return match (true) {
            $context->is(OperationType::Create) && $context->isAtomic() => [Str::make('idempotencyKey')->required(), Str::make('batchTag')->required()],
            $context->is(OperationType::Create) => [Str::make('idempotencyKey')->required()->example('k-1'), DateTime::make('scheduledAt'), Integer::make('attempt')],
            $context->is(OperationType::Update) => [Str::make('reason')],
            $context->is(OperationType::Delete) => [Str::make('reason')->required()],
            $context->isRelationship('items') => [Str::make('reason')],
            default => [],
        };
    }

    public function resourceMeta(MetaContext $context): iterable
    {
        return [
            // A note titled "Broken" renders a revision of the wrong type.
            Integer::make('revision')->readOnly()->extractUsing(static fn(TrNote $note): mixed => $note->title === 'Broken' ? 'many' : $note->revision),
            Str::make($context->isAtomic() ? 'batchSource' : 'source')->writeOnly()->fillUsing(static function (TrNote $note, mixed $value): void {
                $note->source = \is_string($value) ? $value : null;
            }),
            Str::make('label')->writeOnly(),
        ];
    }

    public function documentMeta(OperationType $operation, ?string $relation = null): iterable
    {
        return match (true) {
            $operation === OperationType::AddRelationship => [Integer::make('count')->required()->example(2)->extractUsing(static fn(TrNote $note): int => $note->items->count())],
            $operation === OperationType::UpdateRelationship && $relation === 'lead' => [Str::make('ledBy')->required()->example('3')->extractUsing(static fn(TrNote $note): string => (string) $note->lead?->id)],
            default => [],
        };
    }

    public function beforeCreate(object $entity, HookContext $context): void
    {
        if ($entity instanceof TrNote) {
            $key = $context->writtenMeta()->document('idempotencyKey');
            $entity->createdWith = \is_string($key) ? $key : null;
        }
    }

    public function beforeDelete(object $entity, HookContext $context): void
    {
        self::$deletedBecause = $context->writtenMeta()->document('reason');
    }
}
