<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedMeta;

use haddowg\JsonApi\Resource\DeclaresActionDocumentMetaInterface;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /tm-cards/{id}/-actions/pin` answers the card with how many cards are now pinned,
 * which the handler sets, and the card's title, which a hook reads off the card. Asked by
 * `X-Bogus-Meta`, it sets a member it does not declare.
 */
#[AsJsonApiAction(type: 'tm-cards', path: 'pin')]
final class PinCard implements ActionHandlerInterface, DeclaresActionDocumentMetaInterface
{
    public function documentMeta(int $status): iterable
    {
        return match ($status) {
            200 => [
                Integer::make('pinned')->required()->example(1),
                Str::make('title')->required()->example('Plan')->extractUsing(static fn(TmCard $card): string => $card->title),
            ],
            default => [],
        };
    }

    public function handle(ActionContext $context): DataResponse
    {
        $card = $context->entity();
        \assert($card instanceof TmCard);

        return $context->data($card)->withMeta($context->request()->hasHeader('X-Bogus-Meta') ? ['pinned' => 1, 'bogus' => true] : ['pinned' => 1]);
    }
}
