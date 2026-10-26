<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\RawResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\RawResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `GET /actionWidgets/-actions/export` answers in whichever declared media type the
 * request's Accept picks: a JSON:API meta document, a CSV, a PDF stream or plain JSON.
 */
#[AsJsonApiAction(
    type: 'actionWidgets',
    path: 'export',
    methods: ['GET'],
    scope: ActionScope::Collection,
    responds: [
        new MetaResult(),
        new RawResult('text/csv', schema: ['type' => 'string'], headers: ['Content-Disposition' => 'Names the downloaded file.']),
        new RawResult('application/pdf', binary: true),
        new RawResult('application/json', schema: WidgetExportSummary::class),
    ],
)]
final class ExportWidgets implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse|RawResponse
    {
        return match ($context->responseMediaType()) {
            'text/csv' => $context->raw("id,name\n1,Gear\n", headers: ['Content-Disposition' => 'attachment; filename="widgets.csv"']),
            'application/pdf' => $context->raw(self::stream('%PDF-1.7 widgets')),
            'application/json' => $context->raw('{"count":1}'),
            default => $context->meta(['count' => 1]),
        };
    }

    /**
     * @return resource
     */
    private static function stream(string $content)
    {
        $stream = \fopen('php://memory', 'r+');
        \assert(\is_resource($stream));
        \fwrite($stream, $content);
        \rewind($stream);

        return $stream;
    }
}
