<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\Doctrine;

use Doctrine\ORM\Event\PrePersistEventArgs;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\TxNote;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\TxScenario;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\TxUser;

/**
 * Blames {@see TxScenario::$user} on every note and user persisted, as Gedmo's Blameable
 * does with the security user. A user it was handed after being detached reaches the flush
 * as a new entity, which the cascade inserts again.
 */
final class TxBlameListener
{
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (($entity instanceof TxNote || $entity instanceof TxUser) && $entity->createdBy === null) {
            $entity->createdBy = TxScenario::$user;
        }
    }
}
