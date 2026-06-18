<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Action;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;
use haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Album;

/**
 * `POST /albums/{id}/-actions/reissue` — a **resource-scope `Document`** custom action
 * (G13, bundle ADR 0076, design §2): a non-CRUD verb (re-releasing an album under a
 * new date) hanging off the `albums` type under the reserved `-actions` segment.
 *
 * It is a `Document` action with the default `inputType` (the mount `albums` type), so
 * the request body is negotiated, structurally + semantically validated and hydrated
 * into a fresh {@see Album} — from which the handler reads the new `releasedAt` and
 * applies it onto the resolved album (the two are independent: design §3, "no implicit
 * merge"). It is gated by `security: is_granted('ROLE_ADMIN')`, evaluated at the
 * before-action gate against the resolved album, so only an admin may reissue. The
 * change is persisted through the storage-agnostic {@see DataPersisterRegistry}
 * (handlers own their side-effects), then the updated album is rendered through the
 * default (mount) `albums` serializer.
 */
#[AsJsonApiAction(
    type: 'albums',
    path: 'reissue',
    input: ActionInput::Document,
    security: "is_granted('ROLE_ADMIN')",
)]
final class ReissueAlbum implements ActionHandlerInterface
{
    public function __construct(private readonly DataPersisterRegistry $persisters) {}

    public function handle(ActionContext $context): DataResponse
    {
        $album = $context->entity();
        \assert($album instanceof Album);

        // The hydrated input is an independent Album the request document populated;
        // read its reissue date and apply it onto the resolved album.
        $input = $context->input();
        \assert($input instanceof Album);

        $album->releasedAt = $input->releasedAt;

        $this->persisters->forType('albums')->update('albums', $album);

        return $context->data($album);
    }
}
