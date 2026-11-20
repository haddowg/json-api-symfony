<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteOnlyRelation;

/**
 * The input {@see RevisePinnedCommentsByCommand} builds itself: the ids of the comments an
 * `articles` document names, in the `pinnedComments` column both of its comment relations
 * are stored as.
 */
final class PinnedCommentsCommand
{
    /**
     * @var list<string>
     */
    public array $pinnedComments = [];
}
