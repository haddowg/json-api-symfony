<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EntityHooks;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiBundle\Hook\DeletesEntityInterface;
use haddowg\JsonApiBundle\Hook\HookContext;
use haddowg\JsonApiBundle\Hook\InstantiatesEntityInterface;

/**
 * Builds notes through their constructor and deletes them softly, leaving every
 * other write to the persister.
 */
class HookedNoteResource extends AbstractResource implements InstantiatesEntityInterface, DeletesEntityInterface
{
    public static string $type = 'hooked-notes';

    public function __construct(private readonly DataPersisterRegistry $persisters) {}

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('body'),
            Str::make('createdVia')->readOnly(),
            Boolean::make('deleted')->readOnly(),
        ];
    }

    public function instantiate(HookContext $context): object
    {
        return new HookedNote('');
    }

    public function delete(object $entity, HookContext $context): void
    {
        \assert($entity instanceof HookedNote);
        $entity->deleted = true;
        $this->persisters->forType($context->type)->update($context->type, $entity);
    }
}
