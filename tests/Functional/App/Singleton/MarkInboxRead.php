<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Singleton;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;
use haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry;

/**
 * `POST /inbox/-actions/mark-read`: clears the caller's unread count, and records the inbox
 * once the write commits.
 */
#[AsJsonApiAction(type: 'inboxes', path: 'mark-read', asLink: true)]
final class MarkInboxRead implements ActionHandlerInterface
{
    /**
     * The ids of the inboxes whose write committed, in order.
     *
     * @var list<string>
     */
    public static array $committed = [];

    public function __construct(private readonly DataPersisterRegistry $persisters) {}

    public function handle(ActionContext $context): DataResponse
    {
        $inbox = $context->entity();
        \assert($inbox instanceof Inbox);

        $inbox->unread = 0;
        $this->persisters->forType('inboxes')->update('inboxes', $inbox);
        $id = (string) $inbox->id;
        $context->afterCommit(static function () use ($id): void {
            self::$committed[] = $id;
        });

        return $context->data($inbox);
    }
}
