<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\BinaryUuid;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /books/-actions/list` answers with every book, loaded straight from the entity
 * manager.
 */
#[AsJsonApiAction(type: 'books', path: 'list', scope: ActionScope::Collection)]
final class ListUuidBooks implements ActionHandlerInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function handle(ActionContext $context): DataResponse
    {
        return DataResponse::fromCollection($this->entityManager->getRepository(UuidBookEntity::class)->findBy([], ['title' => 'ASC']), $context->serializer());
    }
}
