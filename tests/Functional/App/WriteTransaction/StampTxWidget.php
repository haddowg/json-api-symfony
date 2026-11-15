<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction;

use haddowg\JsonApi\Exception\AccessDenied;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;
use haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry;

/**
 * A writing action: renames the widget through its persister and writes a note on the side.
 */
#[AsJsonApiAction(type: 'txWidgets', path: 'stamp', responds: [new NoContent()])]
final readonly class StampTxWidget implements ActionHandlerInterface
{
    public function __construct(private DataPersisterRegistry $persisters, private TxSideWriter $writer) {}

    public function handle(ActionContext $context): NoContentResponse
    {
        $widget = $context->entity();
        \assert($widget instanceof TxWidget);

        $widget->name = 'stamped';
        $this->persisters->forType('txWidgets')->update('txWidgets', $widget);
        $this->writer->write('stamped');

        if (TxScenario::$actionThrows) {
            throw new AccessDenied('Refused by the test action.');
        }

        return $context->noContent();
    }
}
