<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction;

use haddowg\JsonApi\Exception\AccessDenied;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;
use haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry;

/**
 * A writing action: renames the widget through its persister and writes a note on the side,
 * then refuses by throwing or by returning an error when the scenario says so.
 */
#[AsJsonApiAction(type: 'txWidgets', path: 'stamp', responds: [new NoContent()])]
final readonly class StampTxWidget implements ActionHandlerInterface
{
    public function __construct(private DataPersisterRegistry $persisters, private TxSideWriter $writer, private TxCallbacks $callbacks) {}

    public function handle(ActionContext $context): NoContentResponse|ErrorResponse
    {
        $widget = $context->entity();
        \assert($widget instanceof TxWidget);

        $widget->name = 'stamped';
        $this->persisters->forType('txWidgets')->update('txWidgets', $widget);
        $this->writer->write('stamped');

        if (TxScenario::$actionAfterCommit) {
            $context->afterCommit($this->callbacks->callback('after commit (stamp)'));
        }

        if (TxScenario::$actionThrows) {
            throw new AccessDenied('Refused by the test action.');
        }

        if (TxScenario::$actionReturnsError) {
            return ErrorResponse::fromException(new AccessDenied('Refused by the test action, recording the stamp.'));
        }

        return $context->noContent();
    }
}
