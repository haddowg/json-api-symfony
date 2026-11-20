<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * Takes an `fk-teams` document by `PATCH` and answers with the members it names, read from
 * the request, and whether its input gained a `members` property: {@see FkTeam} declares
 * none for its foreign-key `members`.
 */
#[AsJsonApiAction(type: 'fk-teams', path: 'regroup', methods: ['PATCH'], input: ActionInput::Document, inputType: 'fk-teams', responds: [new MetaResult()])]
final class RegroupTeam implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        $members = [];
        foreach ($context->request()->getToManyRelationship('members')->resourceIdentifiers as $identifier) {
            $members[] = (string) $identifier->id;
        }

        $input = $context->input();
        \assert($input instanceof FkTeam);

        return $context->meta(['members' => $members, 'inputHoldsMembers' => \array_key_exists('members', \get_object_vars($input))]);
    }
}
