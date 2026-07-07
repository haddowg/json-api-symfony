<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;
use haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry;
use Psr\Http\Message\UploadedFileInterface;

/**
 * `POST /actionWidgets/{id}/-actions/artwork` — the **Raw-input** witness (bundle ADR
 * 0076, design §3/§10): a `multipart/form-data` (or raw blob) upload, NOT
 * `application/vnd.api+json`, so the request content-type negotiation is relaxed and
 * no JSON:API body parsing runs. The handler reads the raw body / uploaded files off
 * {@see ActionContext::request()} (the PSR-7 request), attaches the artwork onto the
 * resolved entity, and returns a bodyless `204` (so it declares `responds: [new NoContent()]`).
 */
#[AsJsonApiAction(type: 'actionWidgets', path: 'artwork', input: ActionInput::Raw, responds: [new NoContent()])]
final class UploadArtwork implements ActionHandlerInterface
{
    public function __construct(private readonly DataPersisterRegistry $persisters) {}

    public function handle(ActionContext $context): NoContentResponse
    {
        $widget = $context->entity();
        \assert($widget instanceof MutableWidget);

        $request = $context->request();

        // Prefer an uploaded file (multipart); fall back to the raw body (a blob POST).
        $files = $request->getUploadedFiles();
        $artwork = null;
        $first = \reset($files);
        if ($first instanceof UploadedFileInterface) {
            $artwork = (string) $first->getStream();
        }

        if ($artwork === null || $artwork === '') {
            $artwork = (string) $request->getBody();
        }

        $widget->attachArtwork($artwork);
        $this->persisters->forType('actionWidgets')->update('actionWidgets', $widget);

        return $context->noContent();
    }
}
