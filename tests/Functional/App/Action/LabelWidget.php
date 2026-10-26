<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `PUT|DELETE /actionWidgets/{id}/-actions/label` sets or clears a widget's label. It
 * answers to two methods, so each gets its own operation id, and it declares its
 * safety and an extension of its own.
 */
#[AsJsonApiAction(
    type: 'actionWidgets',
    path: 'label',
    methods: ['PUT', 'DELETE'],
    responds: [new NoContent()],
    idempotent: true,
    extensions: ['x-workflows' => ['workflowId' => 'labelWidget']],
)]
final class LabelWidget implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        \assert($context->entity() instanceof MutableWidget);

        return $context->noContent();
    }
}
