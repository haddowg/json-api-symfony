<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\Resource\DeclaresActionDocumentMetaInterface;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\AcceptedResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /tr-notes/{id}/-actions/queue` accepts the note for later processing, its 202
 * declaring the queue it joined, which a hook supplies, and the job id, which the
 * handler sets.
 */
#[AsJsonApiAction(type: 'tr-notes', path: 'queue', responds: [new Accepted('tr-jobs')])]
final class QueueNote implements ActionHandlerInterface, DeclaresActionDocumentMetaInterface
{
    public function documentMeta(int $status): iterable
    {
        return $status === 202
            ? [Str::make('queuedAs')->required()->example('job-9'), Str::make('queue')->extractUsing(static fn(mixed $job): string => 'default')]
            : [];
    }

    public function handle(ActionContext $context): AcceptedResponse
    {
        return $context->accepted('https://example.test/tr-jobs/job-9')->withMeta(['queuedAs' => 'job-9']);
    }
}
