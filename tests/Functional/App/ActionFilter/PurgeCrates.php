<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionFilter;

use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * Takes the type's filters and has its own security, which no seeded user passes.
 */
#[AsJsonApiAction(type: 'filterCrates', path: 'purge', scope: ActionScope::Collection, responds: [new NoContent()], security: "is_granted('ROLE_SUPER_ADMIN')", filters: true)]
final class PurgeCrates implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        return $context->noContent();
    }
}
