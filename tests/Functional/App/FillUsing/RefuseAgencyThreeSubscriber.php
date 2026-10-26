<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FillUsing;

use haddowg\JsonApi\Exception\AccessDenied;
use haddowg\JsonApiBundle\Event\BeforeRelationshipCommitEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * An update gate that judges the changed file: pointing a file at agency 3 is refused,
 * so a refusal after the hook has run must roll the write back.
 */
final class RefuseAgencyThreeSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [BeforeRelationshipCommitEvent::class => 'onCommit'];
    }

    public function onCommit(BeforeRelationshipCommitEvent $event): void
    {
        $file = $event->parent;
        if ($file instanceof FillFile && $file->targetType === 'fill-agencies' && $file->targetId === '3') {
            throw new AccessDenied();
        }
    }
}
