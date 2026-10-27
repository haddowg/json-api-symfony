<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Action;

use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\Response\SeeOtherResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * {@see DownloadAlbum} on the `admin` server. The album resource links the download on
 * every server it is served on, so each of them exposes the action.
 */
#[AsJsonApiAction(
    type: 'albums',
    path: 'download',
    methods: ['GET'],
    responds: [new SeeOther(description: 'A short-lived link to the album as CSV.', mediaType: 'text/csv')],
    server: 'admin',
    readOnly: true,
)]
final class AdminDownloadAlbum implements ActionHandlerInterface
{
    public function handle(ActionContext $context): SeeOtherResponse
    {
        return (new DownloadAlbum())->handle($context);
    }
}
