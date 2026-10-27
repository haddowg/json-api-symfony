<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\Response\SeeOtherResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `GET /actionWidgets/{id}/-actions/download` answers with a URL: a location document
 * for a JSON:API request, a `303` for any other.
 */
#[AsJsonApiAction(
    type: 'actionWidgets',
    path: 'download',
    methods: ['GET'],
    responds: [new SeeOther(description: 'A short-lived link to the widget sheet.', mediaType: 'text/csv')],
    readOnly: true,
)]
final class DownloadWidget implements ActionHandlerInterface
{
    public const string EXPIRES_AT = '2030-01-01T00:05:00+00:00';

    public function handle(ActionContext $context): SeeOtherResponse
    {
        $widget = $context->entity();
        \assert($widget instanceof MutableWidget);

        return $context->seeOther(
            'https://storage.example.com/widgets/' . \rawurlencode($widget->widgetName()) . '.csv?signature=test',
            new \DateTimeImmutable(self::EXPIRES_AT),
        );
    }
}
