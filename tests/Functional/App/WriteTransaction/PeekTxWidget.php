<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction;

use haddowg\JsonApi\Exception\AccessDenied;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * A GET action that writes anyway, to show a GET runs without a transaction.
 */
#[AsJsonApiAction(type: 'txWidgets', path: 'peek', methods: ['GET'], responds: [new NoContent()])]
final readonly class PeekTxWidget implements ActionHandlerInterface
{
    public function __construct(private TxSideWriter $writer, private TxCallbacks $callbacks) {}

    public function handle(ActionContext $context): NoContentResponse
    {
        $this->writer->write('peeked');

        if (TxScenario::$actionAfterCommit) {
            $context->afterCommit($this->callbacks->callback('after commit (peek)'));
        }

        if (TxScenario::$actionThrows) {
            throw new AccessDenied('Refused by the test action.');
        }

        return $context->noContent();
    }
}
