<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiHydrator;

/**
 * The standalone hydrator for the bespoke `renameCommands` command type (bundle ADR
 * 0076, design §3): it reads the `newName` attribute off the validated request
 * document and writes it onto the {@see RenameCommand} DTO the action's
 * {@see \haddowg\JsonApiBundle\Action\ActionInputFactoryInterface::newInput()} supplied.
 */
#[AsJsonApiHydrator(type: 'renameCommands')]
final class RenameCommandHydrator implements HydratorInterface
{
    public function hydrate(JsonApiRequestInterface $request, mixed $domainObject): mixed
    {
        \assert($domainObject instanceof RenameCommand);

        $newName = $request->getResourceAttribute('newName', '');
        $domainObject->newName = \is_string($newName) ? $newName : '';

        return $domainObject;
    }
}
