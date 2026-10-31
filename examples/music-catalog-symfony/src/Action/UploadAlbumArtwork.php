<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Action;

use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;
use haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Album;
use Psr\Http\Message\UploadedFileInterface;

/**
 * `POST /albums/{id}/-actions/artwork` — a **Raw-input** custom action (G13,
 * design §3): a binary cover-artwork upload, the escape hatch for a
 * non-JSON:API body. It accepts a `multipart/form-data` form or a bare image blob
 * (`image/*`), so the request content type is matched against those two and no
 * JSON:API body parsing runs; a form type has to be listed to be accepted. The handler
 * reads the raw body / uploaded file straight off {@see ActionContext::request()} (the
 * PSR-7 request), attaches it to the resolved album, persists, and returns a bodyless
 * `204` (so it declares `responds: [new NoContent()]` — the generated document advertises
 * a `204`, not a `200` albums body). Sending the same file
 * again changes nothing more, so the action declares itself idempotent.
 */
#[AsJsonApiAction(
    type: 'albums',
    path: 'artwork',
    input: ActionInput::Raw,
    responds: [new NoContent()],
    accepts: ['multipart/form-data', 'image/*'],
    idempotent: true,
    // Its own scopes, replacing the resolver's: a token that may change albums also needs
    // catalog:media to upload artwork.
    scopes: ['catalog:write', 'catalog:media'],
)]
final class UploadAlbumArtwork implements ActionHandlerInterface
{
    public function __construct(private readonly DataPersisterRegistry $persisters) {}

    public function handle(ActionContext $context): NoContentResponse
    {
        $album = $context->entity();
        \assert($album instanceof Album);

        $request = $context->request();

        // Prefer a multipart uploaded file; fall back to the raw request body (a blob POST).
        $artwork = null;
        $files = $request->getUploadedFiles();
        $first = \reset($files);
        if ($first instanceof UploadedFileInterface) {
            $artwork = (string) $first->getStream();
        }

        if ($artwork === null || $artwork === '') {
            $artwork = (string) $request->getBody();
        }

        $album->artwork = $artwork;
        $this->persisters->forType('albums')->update('albums', $album);

        return $context->noContent();
    }
}
