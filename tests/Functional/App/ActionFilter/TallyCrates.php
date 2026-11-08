<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionFilter;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * Takes every filter of the type and reports the ids it reads, two at a time, and the
 * native query it is offered.
 */
#[AsJsonApiAction(type: 'filterCrates', path: 'tally', scope: ActionScope::Collection, responds: [new MetaResult()], filters: true)]
final class TallyCrates implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        $filtered = $context->filtered();

        $ids = [];
        foreach ($filtered->each(2) as $crate) {
            $ids[] = $context->serializer()->getId($crate);
        }

        $query = $filtered->query();
        $queried = null;
        if ($query instanceof QueryBuilder) {
            $queried = [];
            $rows = $query->getQuery()->getResult();
            self::assertList($rows);
            foreach ($rows as $crate) {
                $queried[] = $context->serializer()->getId($crate);
            }
            \sort($queried);
        }

        return $context->meta([
            'ids' => $ids,
            'native' => $query === null ? null : $query::class,
            'queried' => $queried,
        ]);
    }

    /**
     * @phpstan-assert list<object> $rows
     */
    private static function assertList(mixed $rows): void
    {
        if (!\is_array($rows) || !\array_is_list($rows)) {
            throw new \UnexpectedValueException('Expected a list of crates.');
        }
        foreach ($rows as $row) {
            if (!\is_object($row)) {
                throw new \UnexpectedValueException('Expected a list of crates.');
            }
        }
    }
}
