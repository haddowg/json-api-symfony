<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

/**
 * A bespoke **command** input DTO for the custom-`inputType` action (bundle ADR
 * 0076, design §3): a request document of type `renameCommands` is validated +
 * hydrated into one of these, which the action then applies onto the mount entity.
 *
 * It is backed only by a standalone serializer/hydrator pair (no persister), so the
 * action handler implements {@see \haddowg\JsonApiBundle\Action\ActionInputFactoryInterface}
 * to supply the blank instance.
 */
final class RenameCommand
{
    public function __construct(
        public string $newName = '',
    ) {}
}
