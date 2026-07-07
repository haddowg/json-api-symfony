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
 * `POST /albums/{id}/-actions/artwork` — a **Raw-input** custom action (G13, bundle
 * ADR 0076, design §3): a binary cover-artwork upload, the escape hatch for a
 * non-JSON:API body. Because the upload is `multipart/form-data` / an
 * `application/octet-stream` blob (NOT `application/vnd.api+json`), the request
 * content-type negotiation is relaxed and no JSON:API body parsing runs; the handler
 * reads the raw body / uploaded file straight off {@see ActionContext::request()} (the
 * PSR-7 request), attaches it to the resolved album, persists, and returns a bodyless
 * `204` (so it declares `responds: [new NoContent()]` — the generated document advertises
 * a `204`, not a `200` albums body).
 */
#[AsJsonApiAction(type: 'albums', path: 'artwork', input: ActionInput::Raw, responds: [new NoContent()])]
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
