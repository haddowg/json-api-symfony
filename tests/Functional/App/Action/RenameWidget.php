<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Action\ActionInputFactoryInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;
use haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry;

/**
 * `POST /actionWidgets/{id}/-actions/rename` — the **decoupled document** witness
 * (bundle ADR 0076, design §3/§10): a `Document`-input action whose `inputType`
 * (`renameCommands`) and response type (`receipts`) BOTH differ from the mount type.
 *
 * The bespoke `renameCommands` command rides in (validated + hydrated into a
 * {@see RenameCommand} supplied by {@see newInput()}, since it has no persister), the
 * handler applies its `newName` onto the resolved mount entity, then returns a
 * bespoke `receipts` {@see Receipt} document through the `receipts` serializer — a
 * different request shape in, a different response shape out, both valid JSON:API.
 */
#[AsJsonApiAction(
    type: 'actionWidgets',
    path: 'rename',
    input: ActionInput::Document,
    inputType: 'renameCommands',
    responds: [new ActionResource('receipts')],
)]
final class RenameWidget implements ActionHandlerInterface, ActionInputFactoryInterface
{
    public function __construct(private readonly DataPersisterRegistry $persisters) {}

    public function newInput(JsonApiRequestInterface $body): object
    {
        return new RenameCommand();
    }

    public function handle(ActionContext $context): DataResponse
    {
        $widget = $context->entity();
        \assert($widget instanceof MutableWidget);

        $command = $context->input();
        \assert($command instanceof RenameCommand);

        $widget->applyName($command->newName);
        $this->persisters->forType('actionWidgets')->update('actionWidgets', $widget);

        // Render a bespoke `receipts` document (outputType), decoupled from the mount.
        return $context->data(new Receipt(\sprintf('receipt-%s', $command->newName), $command->newName));
    }
}
