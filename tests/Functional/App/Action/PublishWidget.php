<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;
use haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry;

/**
 * `POST /actionWidgets/{id}/-actions/publish` — the resource-scope, input-`None`
 * witness (bundle ADR 0076, design §10): the `{id}` is resolved to the mount entity
 * before the handler runs, the handler flips `published`, **persists** the change
 * (the handler owns its side-effects — design §3/§4, "apply the action in the
 * handler"), and returns the **mount resource** through the default (`outputType` =
 * mount type) serializer so the response is the published widget document.
 *
 * It persists through the storage-agnostic {@see DataPersisterRegistry} (a service
 * the handler injects, since a handler is an ordinary service), so the same handler
 * commits on either provider — the in-memory persister saves to the shared store, the
 * Doctrine persister flushes the managed entity.
 */
#[AsJsonApiAction(type: 'actionWidgets', path: 'publish')]
final class PublishWidget implements ActionHandlerInterface
{
    public function __construct(private readonly DataPersisterRegistry $persisters) {}

    public function handle(ActionContext $context): DataResponse
    {
        $widget = $context->entity();
        \assert($widget instanceof MutableWidget);

        $widget->publish();
        $this->persisters->forType('actionWidgets')->update('actionWidgets', $widget);

        return $context->data($widget);
    }
}
