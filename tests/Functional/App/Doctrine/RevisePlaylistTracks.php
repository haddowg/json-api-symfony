<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * Takes a `playlists` document by `PATCH`, so the `tracks` it names are compared with the
 * tracks the playlist holds, and changes nothing.
 */
#[AsJsonApiAction(type: 'playlists', path: 'revise', methods: ['PATCH'], input: ActionInput::Document, inputType: 'playlists', responds: [new MetaResult()])]
final class RevisePlaylistTracks implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta(['revised' => true]);
    }
}
