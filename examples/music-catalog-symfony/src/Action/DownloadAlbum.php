<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Action;

use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\Response\SeeOtherResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Album;
use Symfony\Component\Clock\Clock;

/**
 * `GET /albums/{id}/-actions/download` hands out a short-lived link to the album as CSV,
 * standing in for a presigned storage URL. A JSON:API client gets it as the `location`
 * link of a `200` document. A browser following the album's `download` link gets a `303`
 * to the file.
 */
#[AsJsonApiAction(
    type: 'albums',
    path: 'download',
    methods: ['GET'],
    responds: [new SeeOther(description: 'A short-lived link to the album as CSV.', mediaType: 'text/csv')],
    asLink: true,
    readOnly: true,
)]
final class DownloadAlbum implements ActionHandlerInterface
{
    public function handle(ActionContext $context): SeeOtherResponse
    {
        $album = $context->entity();
        \assert($album instanceof Album);

        return SeeOtherResponse::to(
            'https://storage.example.com/albums/' . $album->id . '.csv?signature=example',
            expiresAt: Clock::get()->now()->modify('+5 minutes'),
        );
    }
}
